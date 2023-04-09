<?php declare(strict_types = 1);

/*  Copyright (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com
 *  Distributed under the MIT software license, see the accompanying file LICENSE.md  */

/*  This module processes "internal" EVM transactions (this requires tracing). Only Erigon is supported.  */

abstract class EVMTraceModule extends CoreModule
{
    use EVMTraits;

    public ?BlockHashFormat $block_hash_format = BlockHashFormat::HexWith0x;
    public ?AddressFormat $address_format = AddressFormat::HexWith0x;
    public ?TransactionHashFormat $transaction_hash_format = TransactionHashFormat::HexWith0x;
    public ?TransactionRenderModel $transaction_render_model = TransactionRenderModel::Even;
    public ?FeeRenderModel $fee_render_model = FeeRenderModel::None;
    public ?bool $hidden_values_only = false;

    public ?array $events_table_fields = ['block', 'transaction', 'sort_key', 'time', 'address', 'effect', 'extra'];
    public ?array $events_table_nullable_fields = ['extra'];

    public ?ExtraDataModel $extra_data_model = ExtraDataModel::Default;

    public ?bool $must_complement = true; // Any trace module should complement a main module

    public ?bool $should_return_events = true;
    public ?bool $should_return_currencies = false;
    public ?bool $allow_empty_return_events = true; // Transaaction may have no traces

    public ?bool $mempool_implemented = false;
    public ?bool $forking_implemented = true;

    // EVM-specific

    public ?EVMImplementation $evm_implementation = null;

    //

    final public function pre_initialize()
    {
        $this->version = 1;
    }

    final public function post_post_initialize()
    {
        if (is_null($this->evm_implementation)) throw new DeveloperError("`evm_implementation` is not set (developer error)");
    }

    final public function pre_process_block($block_id)
    {
        if ($this->evm_implementation !== EVMImplementation::Erigon)
        {
            throw new DeveloperError('Only Erigon is currently supported for this module');
        }

        $trace = requester_single($this->select_node(),
            params: ['jsonrpc'=> '2.0', 'method' => 'trace_block', 'params' => [to_0xhex_from_int64($block_id)], 'id' => 0],
            result_in: 'result', timeout: $this->timeout);

        $events = [];

        $current_transaction = '';
        $sort_key = 0;

        foreach ($trace as $bit)
        {
            // We don't need block reward data as this is already processed by the main module
            if (!isset($bit['transactionHash']) && ($bit['type'] === 'reward'))
            {
                continue;
            }

            // New transaction is being processed
            if ($bit['transactionHash'] !== $current_transaction)
            {
                // We don't need the root call as it is already processed by the main module
                $current_transaction = $bit['transactionHash'];

                $root_failed = isset($bit['error']);

                continue;
            }

            // There's an error, but the root call hasn't failed
            if (isset($bit['error']) && !$root_failed)
            {
                continue;
            }

            // Everything has failed, we're not adding any data to save the disk space
            if ($root_failed)
            {
                continue;
            }

            // Now we have several call types
            // `call` transfers ethers
            // `create`/`create2` creates a contract and transfers ethers
            // `callcode`/`delegatecall`/`staticcall` don't transfer ethers
            // `selfdestruct` destroys the contract and gets ethers out of it

            if ($bit['type'] === 'create')
            {
                // from: action.form, to: result.address, value: action.value

                $events[] = [
                    'transaction' => $bit['transactionHash'],
                    'address' => $bit['action']['from'],
                    'sort_key' => $sort_key++,
                    'effect' => '-' . to_int256_from_0xhex($bit['action']['value']),
                    'extra' => EVMSpecialTransactions::ContractCreation->value,
                ];

                $events[] = [
                    'transaction' => $bit['transactionHash'],
                    'address' => $bit['result']['address'],
                    'sort_key' => $sort_key++,
                    'effect' => to_int256_from_0xhex($bit['action']['value']),
                    'extra' => EVMSpecialTransactions::ContractCreation->value,
                ];
            }
            elseif ($bit['type'] === 'suicide')
            {
                // from: action.address, to: action.refundAddress, value: action.balance

                $events[] = [
                    'transaction' => $bit['transactionHash'],
                    'address' => $bit['action']['address'],
                    'sort_key' => $sort_key++,
                    'effect' => '-' . to_int256_from_0xhex($bit['action']['balance']),
                    'extra' => EVMSpecialTransactions::ContractDestruction->value,
                ];

                $events[] = [
                    'transaction' => $bit['transactionHash'],
                    'address' => $bit['action']['refundAddress'],
                    'sort_key' => $sort_key++,
                    'effect' => to_int256_from_0xhex($bit['action']['balance']),
                    'extra' => EVMSpecialTransactions::ContractDestruction->value,
                ];
            }
            elseif ($bit['action']['callType'] === 'call')
            {
                // from: action.from, to: action.to, value: action.value

                if ($bit['action']['value'] === '0x0')
                    continue;

                $events[] = [
                    'transaction' => $bit['transactionHash'],
                    'address' => $bit['action']['from'],
                    'sort_key' => $sort_key++,
                    'effect' => '-' . to_int256_from_0xhex($bit['action']['value']),
                    'extra' => null,
                ];

                $events[] = [
                    'transaction' => $bit['transactionHash'],
                    'address' => $bit['action']['to'],
                    'sort_key' => $sort_key++,
                    'effect' => to_int256_from_0xhex($bit['action']['value']),
                    'extra' => null,
                ];
            }
            elseif (in_array($bit['action']['callType'], ['staticcall', 'delegatecall', 'callcode']))
            {
                // These types don't transfer ethers
                continue;
            }
            else
            {
                throw new ModuleError("Unknown call type for {$bit['transactionHash']}: {$bit['action']['callType']}");
            }
        }

        ////////////////
        // Processing //
        ////////////////

        foreach ($events as &$event)
        {
            $event['block'] = $block_id;
            $event['time'] = $this->block_time;
        }

        $this->set_return_events($events);
    }
}
