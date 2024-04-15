<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the main Avalanche C-Chain module. It requires a geth node to run.  */

final class AvalancheMainModule extends EVMMainModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'avalanche'; // C-Chain
        $this->module = 'avalanche-main';
        $this->is_main = true;
        $this->first_block_date = '2015-07-30'; // That's for block #0, in reality it starts on 2020-09-23 with block #1... ¯\_(ツ)_/¯
        $this->first_block_id = 0;
        $this->mempool_implemented = false; // Unlike other EVMMainModule heirs, Avalanche doesn't implement mempool
        $this->forking_implemented = false; // And all blocks are instantly finalized

        // EVMMainModule
        $this->currency = 'avalanche';
        $this->currency_details = ['name' => 'Avalanche', 'symbol' => 'AVAX', 'decimals' => 18, 'description' => null];
        $this->evm_implementation = EVMImplementation::geth;
        $this->reward_function = function($block_id)
        {
            return '0';
        };

        // Handles
        $this->handles_implemented = true;
        $this->handles_regex = '/(.*)\.avax/';
        $this->api_get_handle = function($handle)
        {
            if (!preg_match($this->handles_regex, $handle))
                return null;

            $address = strtolower(hex2bin(substr(requester_single($this->select_node(),
                params: ['jsonrpc' => '2.0',
                         'method'  => 'eth_call',
                         'id'      => 0,
                         'params'  => [['to'   => '0x1ea4e7a798557001b99d88d6b4ba7f7fc79406a9',
                                        'data' => '0x08991a1d' . $this->encode_abi('string,uint256', [$handle, '3']),
                                       ],
                                       'latest',
                         ],
                ],
                result_in: 'result',
                timeout: $this->timeout), 130, 84)));

            if (!preg_match(StandardPatterns::HexWith0x40->value, $address))
                return null;

            return $address;
        };
    }
}
