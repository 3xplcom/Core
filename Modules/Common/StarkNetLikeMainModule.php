<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes Transfer event of main token in StarkNet - StarkGate: ETH Token */

abstract class StarkNetLikeMainModule extends CoreModule
{
    use StarkNetTraits;

    public ?BlockHashFormat $block_hash_format = BlockHashFormat::HexWith0x;
    public ?AddressFormat $address_format = AddressFormat::HexWith0x;
    public ?TransactionHashFormat $transaction_hash_format = TransactionHashFormat::HexWith0x;
    public ?TransactionRenderModel $transaction_render_model = TransactionRenderModel::Even;
    public ?CurrencyFormat $currency_format = CurrencyFormat::Static;
    public ?CurrencyType $currency_type = CurrencyType::FT;
    public ?FeeRenderModel $fee_render_model = FeeRenderModel::ExtraBF;
    public ?array $special_addresses = ['the-void'];
    public ?PrivacyModel $privacy_model = PrivacyModel::Transparent;

    public ?array $events_table_fields = ['block', 'transaction', 'sort_key', 'time', 'address', 'effect', 'failed', 'extra'];
    public ?array $events_table_nullable_fields = ['transaction', 'extra'];

    public ?ExtraDataModel $extra_data_model = ExtraDataModel::Type;

    public ?bool $should_return_events = true;
    public ?bool $should_return_currencies = false;
    public ?bool $allow_empty_return_events = false;

    public ?bool $mempool_implemented = true;
    public ?bool $forking_implemented = true;

    //

    final public function pre_initialize()
    {
        $this->version = 1;
    }

    final public function post_post_initialize()
    {
        if (is_null($this->currency))
            throw new DeveloperError("`currency` is not set (developer error)");
    }

    final public function pre_process_block($block_id)
    {
        $r1 = requester_single(
            $this->select_node(),
            params: [
                'method'  => 'starknet_getBlockWithTxs',
                'params'  => [['block_number' => $block_id]],
                'id'      => 0,
                'jsonrpc' => '2.0',
            ],
            result_in: 'result',
            timeout: $this->timeout
        );

        $general_data = $r1['transactions'];
        $multi_curl = [];
        $ij = 0;

        foreach ($r1['transactions'] as $transaction) {
            $multi_curl[] = requester_multi_prepare(
                $this->select_node(),
                params: [
                    'method'  => 'starknet_getTransactionReceipt',
                    'params'  => [$transaction['transaction_hash']],
                    'id'      => $ij++,
                    'jsonrpc' => '2.0',
                ],
                timeout: $this->timeout
            );
        }

        $curl_results = requester_multi(
            $multi_curl,
            limit: envm($this->module, 'REQUESTER_THREADS'),
            timeout: $this->timeout
        );

        $receipt_data = requester_multi_process_all($curl_results, result_in: 'result');

        if (($ic = count($general_data)) !== count($receipt_data))
        {
            throw new ModuleError('Mismatch in transaction count');
        }

        $sort_key = 0;

        for ($i = 0; $i < $ic; $i++)
        {
            if ($general_data[$i]['transaction_hash'] !== $receipt_data[$i]['transaction_hash'])
            {
                throw new ModuleError('Mismatch in transaction order');
            }

            foreach($receipt_data[$i]['events'] as $event) 
            {
                if (
                    $event['from_address'] === '0x49d36570d4e46f48e99674bd3fcc84644ddd6b96f7c741b1562b82f9e004dc7' &&
                    $event['keys'][0] === '0x99cd8bde557814842a3121e8ddfd433a539b8c9f14bf31ebf108d12e6196e9'
                ) 
                {
                    $data = $event['data'];
                    if(isset($general_data[$i]['sender_address']) && $data[0] === $general_data[$i]['sender_address']) 
                    {
                        $events[] = [
                            'transaction' => $general_data[$i]['transaction_hash'],
                            'address' => $general_data[$i]['sender_address'],
                            'sort_key' => $sort_key++,
                            'effect' => '-' . hex2dec(substr($receipt_data[$i]['actual_fee'], 2)),
                            'failed' => ($receipt_data[$i]['execution_status'] === 'SUCCEEDED') ? false : true,
                            'extra' => 'f',
                        ];
                        $events[] = [
                            'transaction' => $general_data[$i]['transaction_hash'],
                            'address' => $data[1],
                            'sort_key' => $sort_key++,
                            'effect' => hex2dec(substr($receipt_data[$i]['actual_fee'], 2)),
                            'failed' => ($receipt_data[$i]['execution_status'] === 'SUCCEEDED') ? false : true,
                            'extra' => 'f',
                        ];
                        continue;
                    }
                    if($general_data[$i]['type'] === 'DEPLOY_ACCOUNT') 
                    {
                        $events[] = [
                            'transaction' => $general_data[$i]['transaction_hash'],
                            'address'     => $data[0],
                            'sort_key'    => $sort_key++,
                            'failed'      => false,
                            'effect'      => '-' . to_int256_from_0xhex($data[2]),
                            'extra'       => 'f',
                        ];
            
                        $events[] = [
                            'transaction' => $general_data[$i]['transaction_hash'],
                            'address'     => $data[1],
                            'sort_key'    => $sort_key++,
                            'failed'      => false,
                            'effect'      => to_int256_from_0xhex($data[2]),
                            'extra'       => 'f',
                        ];
                        continue;
                    }
                    $events[] = [
                        'transaction' => $general_data[$i]['transaction_hash'],
                        'address'     => $data[0],
                        'sort_key'    => $sort_key++,
                        'failed'      => false,
                        'effect'      => '-' . to_int256_from_0xhex($data[2]),
                        'extra'       => null,
                    ];
        
                    $events[] = [
                        'transaction' => $general_data[$i]['transaction_hash'],
                        'address'     => $data[1],
                        'sort_key'    => $sort_key++,
                        'failed'      => false,
                        'effect'      => to_int256_from_0xhex($data[2]),
                        'extra'       => null,
                    ];
                }
            }
        }        

        ////////////////
        // Processing //
        ////////////////

        $this_time = date('Y-m-d H:i:s');

        foreach ($events as &$event)
        {
            $event['block'] = $block_id;
            $event['time'] = ($block_id !== MEMPOOL) ? $this->block_time : $this_time;
        }

        $this->set_return_events($events);
    }

    // Getting balances from the node
    public function api_get_balance($address)
    {
        $address = strtolower($address);

        if (!preg_match(StandardPatterns::HexWith0x->value, $address))
            return '0';

        return to_int256_from_0xhex(requester_single(
            $this->select_node(),
            params: [
                'method'  => 'starknet_call',
                'params' => [
                    [
                        'calldata' => ["{$address}"],
                        'contract_address' => "0x049d36570d4e46f48e99674bd3fcc84644ddd6b96f7c741b1562b82f9e004dc7",
                        'entry_point_selector' => "0x2e4263afad30923c891518314c3c95dbe830a16874e8abc5777a9a20b54c76e"
                    ],
                    'latest',
                ],
                'id'      => 0,
                'jsonrpc' => '2.0',
            ],
            result_in: 'result',
            timeout: $this->timeout
        )[0] ?? '0');
    }
}
