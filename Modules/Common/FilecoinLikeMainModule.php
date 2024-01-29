<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*
 *  This module process the main transfer events for Filecoin blockchain.
 *  API Spec: https://docs.filecoin.io/reference/json-rpc/chain
 *  Note: At Filecoin there are tipsets instead of blocks. One tipset it's a set of blocks
 *  with some height and timestamp produced by different block producers.
 *  In the module $block_id means like tipset id.
 */

abstract class FilecoinLikeMainModule extends CoreModule
{
    use FilecoinTraits;

    public ?BlockHashFormat $block_hash_format = BlockHashFormat::AlphaNumeric;
    public ?AddressFormat $address_format = AddressFormat::AlphaNumeric;
    public ?TransactionHashFormat $transaction_hash_format = TransactionHashFormat::AlphaNumeric;
    public ?TransactionRenderModel $transaction_render_model = TransactionRenderModel::Even;
    public ?CurrencyFormat $currency_format = CurrencyFormat::Static;
    public ?CurrencyType $currency_type = CurrencyType::FT;
    public ?FeeRenderModel $fee_render_model = FeeRenderModel::ExtraBF;
    // the-void - special address for mint/burn events.
    public ?array $special_addresses = ['the-void'];
    public ?PrivacyModel $privacy_model = PrivacyModel::Transparent;

    public ?array $events_table_fields = ['block', 'transaction', 'sort_key', 'time', 'address', 'effect', 'failed', 'extra'];
    public ?array $events_table_nullable_fields = ['transaction', 'extra'];

    public ?ExtraDataModel $extra_data_model = ExtraDataModel::Type;
    public ?array $extra_data_details = [
        FilecoinSpecialTransactions::FeeToMiner->value => 'Miner fee',
        FilecoinSpecialTransactions::FeeToBurn->value  => 'Burnt fee',
    ];

    public ?bool $should_return_events = true;
    public ?bool $should_return_currencies = false;
    public ?bool $allow_empty_return_events = true;

    public ?bool $mempool_implemented = true;
    public ?bool $forking_implemented = false;

    final public function pre_initialize()
    {
        $this->version = 1;
    }

    final public function post_post_initialize()
    {
    }

    final public function pre_process_block($block_id)
    {
        $events = [];
        $sort_key = 0;

        if ($block_id === MEMPOOL)
        {
            $pending_messages = requester_single($this->select_node(),
                params: [
                    'method'  => 'Filecoin.MpoolPending',
                    'params'  => [[]],
                    'id'      => 0,
                    'jsonrpc' => '2.0',
                ],
                result_in: 'result',
                timeout: $this->timeout
            );

            foreach ($pending_messages as $message)
            {
                $tx_hash = $message['CID']['/'];
                $from = $message['Message']['From'];
                $to = $message['Message']['To'];
                $amount = $message['Message']['Value'];

                [$sub, $add] = $this->generate_event_pair($tx_hash, $from, $to, $amount, false, $sort_key);
                array_push($events, $sub, $add);
            }

            $this_time = date('Y-m-d H:i:s');
            foreach ($events as &$event)
            {
                $event['block'] = $block_id;
                $event['time'] = $this_time;
            }

            $this->set_return_events($events);
            return;
        }

        $tipset_header = requester_single($this->select_node(),
            params: [
                'method'  => 'Filecoin.ChainGetTipSetByHeight',
                'params'  => [
                    $block_id,
                    []
                ],
                'id'      => 0,
                'jsonrpc' => '2.0',
            ],
            result_in: 'result',
            timeout: $this->timeout
        );

        // Check for empty tipset
        if ((int)$tipset_header['Height'] !== $block_id)
        {
            $this->set_return_events($events);
            return; // Tipset is empty (ex. 3506564)
        }

        // Tipset header at +1 height to get parent messages and receipts
        $tipset_header_next = null;
        $next_height = $block_id;
        do
        {
            // Tipset may be empty so we need to find none empty next tipset
            $next_height++;
            $tipset_header_next = requester_single($this->select_node(),
                params: [
                    'method'  => 'Filecoin.ChainGetTipSetByHeight',
                    'params'  => [
                        $next_height,
                        []
                    ],
                    'id'      => 0,
                    'jsonrpc' => '2.0',
                ],
                result_in: 'result',
                timeout: $this->timeout
            );
        } while ((int)$tipset_header_next['Height'] !== $next_height);

        // Parent info the same for all block Cids.
        $block_cid_next = $tipset_header_next['Cids'][0];

        // Tipset messages is transactions we parse
        $tipset_messages = requester_single($this->select_node(),
            params: [
                'method'  => 'Filecoin.ChainGetParentMessages',
                'params'  => [
                    $block_cid_next
                ],
                'id'      => 0,
                'jsonrpc' => '2.0',
            ],
            timeout: $this->timeout
        );

        if (is_null($tipset_messages['result']))
        {
            $this->set_return_events($events);
            return; // Tipset is empty (ex. 3504986)
        }

        $tipset_messages = $tipset_messages['result'];

        // Messages Receipts with additional info
        $tipset_receipts = requester_single($this->select_node(),
            params: [
                'method'  => 'Filecoin.ChainGetParentReceipts',
                'params'  => [
                    $block_cid_next
                ],
                'id'      => 0,
                'jsonrpc' => '2.0',
            ],
            result_in: 'result',
            timeout: $this->timeout
        );

        if (count($tipset_messages) !== count($tipset_receipts))
            throw new ModuleException("Messages and receipts count mismatch.");

        // We need to get baseFee value its the same in all blocks headers.
        $base_fee = null;
        // We need to get miner info for every message
        $miners = [];
        foreach ($tipset_header['Cids'] as $block_cid)
        {
            $block_header = requester_single($this->select_node(),
                params: [
                    'method'  => 'Filecoin.ChainGetBlock',
                    'params'  => [
                        $block_cid
                    ],
                    'id'      => 0,
                    'jsonrpc' => '2.0',
                ],
                result_in: 'result',
                timeout: $this->timeout
            );

            // Additional check that base_fee same for all blocks
            if (is_null($base_fee))
                $base_fee = $block_header['ParentBaseFee'];
            elseif ($base_fee !== $block_header['ParentBaseFee'])
                throw new ModuleException("Base fee mismatch for blocks.");

            $block_messages = requester_single($this->select_node(),
                params: [
                    'method'  => 'Filecoin.ChainGetBlockMessages',
                    'params'  => [
                        $block_cid
                    ],
                    'id'      => 0,
                    'jsonrpc' => '2.0',
                ],
                result_in: 'result',
                timeout: $this->timeout
            );

            // Messages can duplicates so in tipset they come in straight order
            foreach ($block_messages['BlsMessages'] as $msg)
            {
                $cid = $msg['CID']['/'];
                if (!array_key_exists($cid, $miners))
                    $miners[$cid] = $block_header['Miner'];
            }

            foreach ($block_messages['SecpkMessages'] as $msg)
            {
                $cid = $msg['CID']['/'];
                if (!array_key_exists($cid, $miners))
                    $miners[$cid] = $block_header['Miner'];
            }
        }

        if (is_null($base_fee))
            throw new ModuleException("Invalid base_fee (null).");

        for ($i = 0; $i < count($tipset_messages); $i++)
        {
            $message = $tipset_messages[$i];
            $receipt = $tipset_receipts[$i];

            $tx_hash = $message['Cid']['/'];
            $failed = false;
            if ((int)$receipt['ExitCode'] !== 0)
                $failed = true;

            // Processing Fee
            // Docs:
            // https://docs.filecoin.io/smart-contracts/filecoin-evm-runtime/how-gas-works#calculation-example
            // https://filecoin.io/blog/posts/filecoin-features-gas-fees/
            // https://spec.filecoin.io/#section-systems.filecoin_vm.gas_fee

            $gas_fee_cap = $message['Message']['GasFeeCap'];
            $gas_limit = $message['Message']['GasLimit'];
            $gas_used = $receipt['GasUsed'];
            $gas_premium = $message['Message']['GasPremium'];

            $miner_fee = null;
            if (bccomp(bcadd($base_fee, $gas_premium), $gas_fee_cap) === 1)
                $miner_fee = bcmul($gas_limit, bcsub($gas_fee_cap, $base_fee));
            else
                $miner_fee = bcmul($gas_limit, $gas_premium);

            $base_to_burn = bcmul($gas_used, $base_fee);
            $over_estimation_burn = $this->compute_gas_overestimation_burn($gas_used, $gas_limit, $base_fee);
            $total_burned = bcadd($base_to_burn, $over_estimation_burn);

            $from = $message['Message']['From'];
            $to = $message['Message']['To'];
            $amount = $message['Message']['Value'];

            if ($miner_fee !== '0')
            {
                [$sub, $add] = $this->generate_event_pair($tx_hash, $from, $miners[$tx_hash], $miner_fee, $failed, $sort_key);
                $sub['extra'] = FilecoinSpecialTransactions::FeeToMiner->value;
                $add['extra'] = FilecoinSpecialTransactions::FeeToMiner->value;
                array_push($events, $sub, $add);
            }
            if ($total_burned !== '0')
            {
                [$sub, $add] = $this->generate_event_pair($tx_hash, $from, 'the-void', $total_burned, $failed, $sort_key);
                $sub['extra'] = FilecoinSpecialTransactions::FeeToBurn->value;
                $add['extra'] = FilecoinSpecialTransactions::FeeToBurn->value;
                array_push($events, $sub, $add);
            }

            if ($amount !== '0')
            {
                [$sub, $add] = $this->generate_event_pair($tx_hash, $from, $to, $amount, $failed, $sort_key);
                array_push($events, $sub, $add);
            }
        }

        foreach ($events as &$event)
        {
            $event['block'] = $block_id;
            $event['time'] = $this->block_time;
        }

        $this->set_return_events($events);
    }

    // Getting balances from the node
    public function api_get_balance($address)
    {
        $balance_data = requester_single($this->select_node(),
            params: [
                'method'  => 'Filecoin.WalletBalance',
                'params'  => [$address],
                'id'      => 0,
                'jsonrpc' => '2.0',
            ],
            timeout: $this->timeout,
            ignore_errors: true,
            valid_codes: [200, 500]
        );

        if (!isset($balance_data['result']))
            return '0';

        return $balance_data['result'];
    }
}
