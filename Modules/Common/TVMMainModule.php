<?php declare(strict_types=1);

/*  Copyright (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com
 *  Distributed under the MIT software license, see the accompanying file LICENSE.md  */

/*  This module processes "external" TVM transactions, block rewards.
 *  Supported nodes: java-tron with the following fix https://github.com/tronprotocol/java-tron/pull/5469 */

abstract class TVMMainModule extends CoreModule
{
    use TVMTraits;

    public ?BlockHashFormat $block_hash_format = BlockHashFormat::HexWithout0x;
    public ?AddressFormat $address_format = AddressFormat::AlphaNumeric;
    public ?TransactionHashFormat $transaction_hash_format = TransactionHashFormat::HexWithout0x;
    public ?TransactionRenderModel $transaction_render_model = TransactionRenderModel::Even;
    public ?CurrencyFormat $currency_format = CurrencyFormat::Static;
    public ?CurrencyType $currency_type = CurrencyType::FT;
    public ?FeeRenderModel $fee_render_model = FeeRenderModel::ExtraBF;
    public ?array $special_addresses = ['the-void', 'treasury', 'dex'];
    public ?PrivacyModel $privacy_model = PrivacyModel::Transparent;

    public ?array $events_table_fields = ['block', 'transaction', 'sort_key', 'time', 'address', 'effect', 'failed', 'extra'];
    public ?array $events_table_nullable_fields = ['transaction'];

    public ?ExtraDataModel $extra_data_model = ExtraDataModel::Type;
    public ?array $extra_data_details = null;
    public ?bool $should_return_events = true;
    public ?bool $should_return_currencies = false;
    public ?bool $allow_empty_return_events = false;

    public ?bool $mempool_implemented = false;
    public ?bool $forking_implemented = true;

    // TVM-specific
    public array $extra_features = [];
    public ?Closure $reward_function = null;


    final public function pre_initialize()
    {
        $this->version = 1;
    }

    final public function post_post_initialize()
    {
        if (is_null($this->currency))
            throw new DeveloperError("`currency` is not set (developer error)");

        if (is_null($this->extra_data_details))
            throw new DeveloperError("`extra_data_details` is not set (developer error)");

        if (is_null($this->reward_function))
            throw new DeveloperError("`reward_function` is not set (developer error)");
    }

    final public function pre_process_block($block_id)
    {
        ////////////////////////////////
        // Getting data from the node //
        ////////////////////////////////

        $transaction_data = [];

        if ($block_id !== MEMPOOL)
        {
            $r1 = requester_single($this->select_node(),
                endpoint: "/wallet/getblockbynum?num={$block_id}&visible=true",
                timeout: $this->timeout);

            $r2 = requester_single($this->select_node(),
                endpoint: "/",
                params: ['method' => 'eth_getBlockByNumber',
                    'params' => [to_0xhex_from_int64($block_id), true],
                    'id' => 0,
                    'jsonrpc' => '2.0',
                ], result_in: 'result', timeout: $this->timeout);

            try
            {
                $receipt_data = requester_single($this->select_node(),
                    endpoint: "/wallet/gettransactioninfobyblocknum?num={$block_id}&visible=true",
                    timeout: $this->timeout);
            }
            catch (RequesterEmptyArrayInResponseException)
            {
                $receipt_data = [];
            }

            $general_data = $r1['transactions'] ?? [];

            // we can't get 'from','to' the other way
            // when we don't know params of specific system contract
            // but evm like jsonrpc response is the way
            $evm_transaction_data = $r2['transactions'];

            $this->block_time = to_timestamp_from_long_unixtime($r1['block_header']['raw_data']['timestamp'] ?? '1529891469000');

            $miner = $r1['block_header']['raw_data']['witness_address'];

            // Data processing
            // $receipt_data can be empty
            if ((($ic = count($general_data)) !== count($receipt_data)) && ($ic !== count($evm_transaction_data))) {
                throw new ModuleError('Mismatch in transaction count');
            }
            for ($i = 0; $i < $ic; $i++)
            {
                if ((count($receipt_data) > 0) && ($general_data[$i]['txID'] !== $receipt_data[$i]['id']) && ($general_data[$i]['txID'] != substr($evm_transaction_data[$i]['hash'], 2)))
                {
                    throw new ModuleError('Mismatch in transaction order');
                }

                if (!isset($general_data[$i]['raw_data']['contract'][0]['parameter']['value']))
                {
                    throw new ModuleError("Error in transaction {$general_data[$i]['txID']} data: no raw_data.contract.parameter.value");
                }
                if (count($general_data[$i]['raw_data']['contract']) > 1)
                {
                    throw new ModuleError("Error in transaction {$general_data[$i]['txID']} data: more than 1 raw_data.contract element");
                }

                if (isset($general_data[$i]['ret']) && count($general_data[$i]['ret']) > 1) // found in block 9972983
                {
                    foreach ($general_data[$i]['ret'] as $ret)
                    {
                        if (isset($ret["contractRet"]))
                        {
                            $general_data[$i]['ret'] = $ret;
                            break;
                        }
                    }
                }

                if (!isset($general_data[$i]['raw_data']['contract'][0]['type']))
                {
                    throw new ModuleError("Contract interaction type missing in {$general_data[$i]['txID']}.");
                }

                $data = $general_data[$i]['raw_data']['contract'][0]['parameter']['value'];
                $transaction_type = $general_data[$i]['raw_data']['contract'][0]['type'];

                if (!in_array($transaction_type, $this->extra_data_details)) {
                    throw new ModuleError("Unknown contract interaction type {$transaction_type} in {$general_data[$i]['txID']}.");
                }

                switch ($transaction_type) {
                    case "AccountCreateContract":
                        $transaction_data[($general_data[$i]['txID'])] =
                            [
                                'from' => $data['owner_address'],
                                'to' => $data['account_address'],
                                'value' => 0,
                                'contractAddress' => null,
                                'fee' => $receipt_data[$i]["fee"] ?? 0,
                                'status' => ($general_data[$i]['ret'][0]['contractRet'] ?? "SUCCESS") != "SUCCESS",
                                'extra' => TVMSpecialTransactions::fromName($transaction_type)

                            ];
                        break;
                    case "TransferContract":
                        $transaction_data[($general_data[$i]['txID'])] =
                            [
                                'from' => $data['owner_address'],
                                'to' => $data['to_address'],
                                'value' => $data['amount'] ?? 0,
                                'contractAddress' => null,
                                'fee' => $receipt_data[$i]["fee"] ?? 0,
                                'status' => ($general_data[$i]['ret'][0]['contractRet'] ?? "SUCCESS") != "SUCCESS",
                                'extra' => TVMSpecialTransactions::fromName($transaction_type)
                            ];
                        break;
                    case "VoteWitnessContract":
                    case "WitnessUpdateContract":
                    case "AccountUpdateContract":
                    case "ProposalCreateContract":
                    case "ProposalApproveContract":
                    case "ProposalDeleteContract":
                    case "MarketSellAssetContract":
                    case "MarketCancelOrderContract":
                    case "SetAccountIdContract":
                    case "AccountPermissionUpdateContract":
                    case "UpdateBrokerageContract":
                    case "WitnessCreateContract":
                        $transaction_data[($general_data[$i]['txID'])] =
                            [
                                'from' => $data['owner_address'],
                                'to' => null,
                                'value' => 0,
                                'contractAddress' => null,
                                'fee' => $receipt_data[$i]["fee"] ?? 0,
                                'status' => ($general_data[$i]['ret'][0]['contractRet'] ?? "SUCCESS") != "SUCCESS",
                                'extra' => TVMSpecialTransactions::fromName($transaction_type)
                            ];
                        break;
                    case "FreezeBalanceContract":
                        $transaction_data[($general_data[$i]['txID'])] =
                            [
                                'from' => $data['owner_address'],
                                'to' => $data['receiver_address'] ?? null,
                                'value' => $data['frozen_balance'],
                                'contractAddress' => null,
                                'fee' => $receipt_data[$i]["fee"] ?? 0,
                                'status' => ($general_data[$i]['ret'][0]['contractRet'] ?? "SUCCESS") != "SUCCESS",
                                'extra' => TVMSpecialTransactions::fromName($transaction_type)
                            ];
                        break;
                    case "UnfreezeBalanceContract":
                        $from = $data['receiver_address'] ?? null;
                        $transaction_data[($general_data[$i]['txID'])] =
                            [
                                'from' => null, // owner_address shouldn't be put here as he staked balance previously
                                'to' => is_null($from) ? $data['owner_address'] : $data['receiver_address'],
                                'value' => $receipt_data[$i]['unfreeze_amount'],
                                'contractAddress' => null,
                                'fee' => $receipt_data[$i]["fee"] ?? 0,
                                'status' => ($general_data[$i]['ret'][0]['contractRet'] ?? "SUCCESS") != "SUCCESS",
                                'extra' => TVMSpecialTransactions::fromName($transaction_type)
                            ];
                        break;
                    case "WithdrawBalanceContract":
                        $transaction_data[($general_data[$i]['txID'])] =
                            [
                                'from' => 'treasury',
                                'to' => $data['owner_address'],
                                'value' => $receipt_data[$i]["withdraw_amount"],
                                'contractAddress' => null,
                                'fee' => $receipt_data[$i]["fee"] ?? 0,
                                'status' => ($general_data[$i]['ret'][0]['contractRet'] ?? "SUCCESS") != "SUCCESS",
                                'extra' => TVMSpecialTransactions::fromName($transaction_type)
                            ];
                        break;
                    case "CreateSmartContract":
                        $transaction_data[($general_data[$i]['txID'])] =
                            [
                                'from' => $data['owner_address'],
                                'to' => null,
                                'value' => 0,
                                'contractAddress' => $receipt_data[$i]['contract_address'],
                                'fee' => $receipt_data[$i]["fee"] ?? 0,
                                'status' => ($general_data[$i]['ret'][0]['contractRet'] ?? "SUCCESS") != "SUCCESS",
                                'extra' => TVMSpecialTransactions::fromName($transaction_type)
                            ];
                        break;
                    case "UpdateEnergyLimitContract":
                    case "ClearABIContract":
                    case "UpdateSettingContract":
                        $transaction_data[($general_data[$i]['txID'])] =
                            [
                                'from' => $data['owner_address'],
                                'to' => $data['contract_address'],
                                'value' => 0,
                                'contractAddress' => null,
                                'fee' => $receipt_data[$i]["fee"] ?? 0,
                                'status' => ($general_data[$i]['ret'][0]['contractRet'] ?? "SUCCESS") != "SUCCESS",
                                'extra' => TVMSpecialTransactions::fromName($transaction_type)
                            ];
                        break;
                    case "FreezeBalanceV2Contract":
                        $transaction_data[($general_data[$i]['txID'])] =
                            [
                                'from' => $data['owner_address'],
                                'to' => null,
                                'value' => $data['frozen_balance'],
                                'contractAddress' => null,
                                'fee' => $receipt_data[$i]["fee"] ?? 0,
                                'status' => ($general_data[$i]['ret'][0]['contractRet'] ?? "SUCCESS") != "SUCCESS",
                                'extra' => TVMSpecialTransactions::fromName($transaction_type)
                            ];
                        break;
                    case "UnfreezeBalanceV2Contract":
                        $transaction_data[($general_data[$i]['txID'])] =
                            [
                                'from' => null,
                                'to' => $data['owner_address'],
                                'value' => $data['unfreeze_balance'],
                                'contractAddress' => null,
                                'fee' => $receipt_data[$i]["fee"] ?? 0,
                                'status' => ($general_data[$i]['ret'][0]['contractRet'] ?? "SUCCESS") != "SUCCESS",
                                'extra' => TVMSpecialTransactions::fromName($transaction_type)
                            ];
                        break;
                    case "WithdrawExpireUnfreezeContract":
                        $transaction_data[($general_data[$i]['txID'])] =
                            [
                                'from' => null,
                                'to' => $data['owner_address'],
                                'value' => $receipt_data[$i]['withdraw_expire_amount'],
                                'contractAddress' => null,
                                'fee' => $receipt_data[$i]["fee"] ?? 0,
                                'status' => ($general_data[$i]['ret'][0]['contractRet'] ?? "SUCCESS") != "SUCCESS",
                                'extra' => TVMSpecialTransactions::fromName($transaction_type)
                            ];
                        break;
                    case "DelegateResourceContract":
                        $transaction_data[($general_data[$i]['txID'])] =
                            [
                                'from' => $data['owner_address'],
                                'to' => $data['receiver_address'],
                                'value' => $data['balance'],
                                'contractAddress' => null,
                                'fee' => $receipt_data[$i]["fee"] ?? 0,
                                'status' => ($general_data[$i]['ret'][0]['contractRet'] ?? "SUCCESS") != "SUCCESS",
                                'extra' => TVMSpecialTransactions::fromName($transaction_type)
                            ];
                        break;
                    case "UndelegateResourceContract":
                        $transaction_data[($general_data[$i]['txID'])] =
                            [
                                'from' => $data['receiver_address'],
                                'to' => $data['owner_address'],
                                'value' => $data['balance'],
                                'contractAddress' => null,
                                'fee' => $receipt_data[$i]["fee"] ?? 0,
                                'status' => ($general_data[$i]['ret'][0]['contractRet'] ?? "SUCCESS") != "SUCCESS",
                                'extra' => TVMSpecialTransactions::fromName($transaction_type)
                            ];
                        break;
                    case "CancelAllUnfreezeV2Contract":
                        $transaction_data[($general_data[$i]['txID'])] =
                            [
                                'from' => null,
                                'to' => $data['owner_address'],
                                'value' => to_int256_from_0xhex($r2['transactions'][$i]['value']),
                                'contractAddress' => null,
                                'fee' => $receipt_data[$i]["fee"] ?? 0,
                                'status' => ($general_data[$i]['ret'][0]['contractRet'] ?? "SUCCESS") != "SUCCESS",
                                'extra' => TVMSpecialTransactions::fromName($transaction_type)
                            ];
                        break;
                    // Exchanges
                    case "ExchangeInjectContract":
                        if ($data['token_id'] == "_") {
                            $transaction_data[($general_data[$i]['txID'])] =
                                [
                                    'from' => $data['owner_address'],
                                    'to' => 'dex',
                                    'value' => $data['quant'],
                                    'contractAddress' => null,
                                    'fee' => $receipt_data[$i]["fee"] ?? 0,
                                    'status' => ($general_data[$i]['ret'][0]['contractRet'] ?? "SUCCESS") != "SUCCESS",
                                    'extra' => TVMSpecialTransactions::fromName($transaction_type)
                                ];
                            break; // only inside if, because we need to process the fee if this is not a trx dex transfer
                        }
                        goto fee_process; // don't iterate over other cases just go to default case
                    case "ExchangeTransactionContract":
                        $exchange = $this->get_exchange_by_id($data['exchange_id']);
                        if ($exchange['has_trx'] && $data['token_id'] != "_") // buying trx for token 4067933
                        {
                            $transaction_data[($general_data[$i]['txID'])] =
                                [
                                    'from' => 'dex',
                                    'to' => $data['owner_address'],
                                    'value' => $receipt_data[$i]['exchange_received_amount'] ?? 0, // for failed transaction it can be unset not sure
                                    'contractAddress' => null,
                                    'fee' => $receipt_data[$i]["fee"] ?? 0,
                                    'status' => ($general_data[$i]['ret'][0]['contractRet'] ?? "SUCCESS") != "SUCCESS",
                                    'extra' => TVMSpecialTransactions::fromName($transaction_type)
                                ];
                            break; // only inside if, because we need to process the fee if this is not a trx dex transfer
                        }
                        elseif ($exchange['has_trx'] && $data['token_id'] === "_") // buying token for trx
                        {
                            $transaction_data[($general_data[$i]['txID'])] =
                                [
                                    'from' => $data['owner_address'],
                                    'to' => 'dex',
                                    'value' => $data['quant'],
                                    'contractAddress' => null,
                                    'fee' => $receipt_data[$i]["fee"] ?? 0,
                                    'status' => ($general_data[$i]['ret'][0]['contractRet'] ?? "SUCCESS") != "SUCCESS",
                                    'extra' => TVMSpecialTransactions::fromName($transaction_type)
                                ];
                            break; // only inside if, because we need to process the fee if this is not a trx dex transfer
                        }
                        goto fee_process;
                    case "ExchangeWithdrawContract":
                        if ($data['token_id'] == "_") {
                            $transaction_data[($general_data[$i]['txID'])] =
                                [
                                    'from' => 'dex',
                                    'to' => $data['owner_address'],
                                    'value' => $data['quant'],
                                    'contractAddress' => null,
                                    'fee' => $receipt_data[$i]["fee"] ?? 0,
                                    'status' => ($general_data[$i]['ret'][0]['contractRet'] ?? "SUCCESS") != "SUCCESS",
                                    'extra' => TVMSpecialTransactions::fromName($transaction_type)
                                ];
                            break; // only inside if, because we need to process the fee if this is not a trx dex transfer
                        }
                        goto fee_process; // fail case in 5969073
                    case "ExchangeCreateContract":
                        if (($data['first_token_id'] == "_") || ($data['second_token_id'] == "_"))
                            $value = $data['first_token_id'] == '_' ? $data['first_token_balance'] : ($data['second_token_id'] == "_" ? $data['second_token_balance'] : null);
                        if (!is_null($value ?? null))
                        {
                            $transaction_data[($general_data[$i]['txID'])] =
                                [
                                    'from' => $data['owner_address'],
                                    'to' => 'dex',
                                    'value' => $value,
                                    'contractAddress' => null,
                                    'fee' => $receipt_data[$i]["fee"] ?? 0,
                                    'status' => ($general_data[$i]['ret'][0]['contractRet'] ?? "SUCCESS") != "SUCCESS",
                                    'extra' => TVMSpecialTransactions::fromName($transaction_type)
                                ];
                            break; // only inside if, because we need to process the fee if this is not a trx dex transfer
                        }
                        goto fee_process;
                    case "ParticipateAssetIssueContract":
                        $transaction_data[($general_data[$i]['txID'])] =
                            [
                                'from' => $data['owner_address'],
                                'to' => $data['to_address'] ?? null,
                                'value' => $data['amount'],
                                'contractAddress' => null,
                                'fee' => $receipt_data[$i]["fee"] ?? 0,
                                'status' => ($general_data[$i]['ret'][0]['contractRet'] ?? "SUCCESS") != "SUCCESS",
                                'extra' => TVMSpecialTransactions::fromName($transaction_type)
                            ];
                        break;

                    // unknown processing rules/default case
                    case "ShieldedTransferContract":
                    case "CustomContract":
                    case "GetContract":
                    default:
                        fee_process:
                            $from = $this->encode_address_to_base58($evm_transaction_data[$i]['from']);
                            $to = $this->encode_address_to_base58($evm_transaction_data[$i]['to']);
                            $value = to_int256_from_0xhex($evm_transaction_data[$i]['value']);
                            if ($transaction_type === "TriggerSmartContract")
                                $value = 0; // exclude double transfer, as it will be processed in internal module
                            // ShieldedTransferContract has additional fee in `shielded_transaction_fee` key
                            $fee = ($receipt_data[$i]["fee"] ?? 0) + ($receipt_data[$i]['shielded_transaction_fee'] ?? 0);
                            $transaction_data[($general_data[$i]['txID'])] =
                                    [
                                        'from' => $from,
                                        'to' => $to,
                                        'value' => $value,
                                        'contractAddress' => null,
                                        'fee' => $fee,
                                        'status' => ($general_data[$i]['ret'][0]['contractRet'] ?? "SUCCESS") != "SUCCESS",
                                        'extra' => TVMSpecialTransactions::fromName($transaction_type)
                                    ];
                            break;
                }

            }
        }
//        else // Mempool processing
//        {
//            // wallet/getpendingsize
//            // wallet/gettransactionlistfrompending only list of transaction ids
//            // wallet/gettransactionfrompending single tx info
//            // Mempool transactions are validated very fast
//        }

        // check for missing transactions
        $processed = count(array_keys($transaction_data));
        $transaction_count = count(array_column($general_data,'txID'));
        if ($processed != $transaction_count )
            throw new ModuleError("Some transactions left unprocessed: {$processed}/{$transaction_count}");


        //////////////////////
        // Preparing events //
        //////////////////////

        $events = [];
        $ijk = 0;

        foreach ($transaction_data as $transaction_hash => $transaction) {
            if ($block_id !== MEMPOOL) {
                // all fees are burned
                $this_burned = strval($transaction['fee']);
            } else {
                $this_burned = '0';
            }

            // Burning
            if ($this_burned !== '0') {
                $events[] = [
                    'transaction' => $transaction_hash,
                    'address' => $transaction['from'] ?? $transaction['to'],
                    'sort_in_block' => $ijk,
                    'sort_in_transaction' => 0,
                    'effect' => '-' . $this_burned,
                    'failed' => false,
                    'extra' => TVMSpecialTransactions::Burning->value,
                ];

                $events[] = [
                    'transaction' => $transaction_hash,
                    'address' => 'the-void',
                    'sort_in_block' => $ijk,
                    'sort_in_transaction' => 1,
                    'effect' => $this_burned,
                    'failed' => false,
                    'extra' => TVMSpecialTransactions::Burning->value,
                ];
            }

            // The action itself
            $val = $transaction['value'] < 0 ? (int)(substr($transaction['value'],1)): $transaction['value'];
            $events[] = [
                'transaction' => $transaction_hash,
                'address' => $transaction['from'] ?? 'the-void',
                'sort_in_block' => $ijk,
                'sort_in_transaction' => 4,
                'effect' => '-' . $val,
                'failed' => $transaction['status'],
                'extra' => $transaction['extra'],
            ];

            if (in_array(TVMSpecialFeatures::AllowEmptyRecipient, $this->extra_features))
                $recipient = $transaction['to'] ?? $transaction['contractAddress'] ?? 'the-void';
            else
                $recipient = $transaction['to'] ?? $transaction['contractAddress'] ?? throw new DeveloperError("No address {$transaction_hash}");
            $events[] = [
                'transaction' => $transaction_hash,
                'address' => $recipient,
                'sort_in_block' => $ijk++,
                'sort_in_transaction' => 5,
                'effect' => strval($val),
                'failed' => $transaction['status'],
                'extra' => $transaction['extra']
                ,
            ];
        }

        if ($block_id !== MEMPOOL) {
            // ToDo get these values from the node
            // but nodes are not aware of old values
            // /wallet/getchainparameters
            // $this_to_miner = response['chainParameter'][$k]['getWitnessPayPerBlock']
            // $this_to_votersresponse['chainParameter'][$k+$n]['getWitness127PayPerBlock']
            // proposal #5 applied on 2019-11-05
            [$this_to_miner, $this_to_voters] = ($this->reward_function)($block_id);

            // SR reward

            $events[] = [
                'transaction' => null,
                'address' => 'the-void',
                'sort_in_block' => $ijk,
                'sort_in_transaction' => 0,
                'effect' => '-' . $this_to_miner,
                'failed' => false,
                'extra' => TVMSpecialTransactions::BlockReward->value,
            ];

            $events[] = [
                'transaction' => null,
                'address' => $miner,
                'sort_in_block' => $ijk++,
                'sort_in_transaction' => 1,
                'effect' => $this_to_miner,
                'failed' => false,
                'extra' => TVMSpecialTransactions::BlockReward->value,
            ];

            // Voters rewards (SR partners - 100 voters)

            $events[] = [
                'transaction' => null,
                'address' => 'the-void',
                'sort_in_block' => $ijk++,
                'sort_in_transaction' => 1,
                'effect' => '-' . $this_to_voters,
                'failed' => false,
                'extra' => TVMSpecialTransactions::PartnerReward->value,
            ];

            $events[] = [
                'transaction' => null,
                'address' => 'treasury',
                'sort_in_block' => $ijk++,
                'sort_in_transaction' => 1,
                'effect' => $this_to_voters,
                'failed' => false,
                'extra' => TVMSpecialTransactions::PartnerReward->value,
            ];
        }

        ////////////////
        // Processing //
        ////////////////

        $this_time = date('Y-m-d H:i:s');
        foreach ($events as &$event) {
            $event['block'] = $block_id;
            $event['time'] = ($block_id !== MEMPOOL) ? $this->block_time : $this_time;
        }
        // Resort

        if ($block_id !== MEMPOOL) {
            usort($events, function ($a, $b) {
                return [$a['sort_in_block'],
                        $a['sort_in_transaction'],
                    ]
                    <=>
                    [$b['sort_in_block'],
                        $b['sort_in_transaction'],
                    ];
            });
        }

        $sort_key = 0;

        $this_transaction = '';

        foreach ($events as &$event) {
            if ($block_id === MEMPOOL) {
                if ($this_transaction != $event['transaction']) {
                    $this_transaction = $event['transaction'];
                    $sort_key = 0;
                }
            }

            $event['sort_key'] = $sort_key;
            $sort_key++;

            unset($event['sort_in_block']);
            unset($event['sort_in_transaction']);
        }

        $this->set_return_events($events);
    }

    // Getting balances from the node
    public function api_get_balance($address): ?string
    {
        // assuming that address received  in base58 format THPvaUhoh2Qn2y9THCZML3H815hhFhn5YC
        // should always be the case
        try {
            $address = '0x' . $this->encode_base58_to_evm_hex($address);
        } catch (Exception) {
            return '0';
        }

        // let's keep this extra check
        if (!preg_match(StandardPatterns::iHexWith0x40->value, $address))
            return '0';

        return to_int256_from_0xhex(requester_single($this->select_node(),
            endpoint: "/",
            params: ['jsonrpc' => '2.0', 'method' => 'eth_getBalance', 'params' => [$address, 'latest'], 'id' => 0],
            result_in: 'result', timeout: $this->timeout));
    }
}
