<?php declare(strict_types = 1);

/*  Copyright (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com
 *  Distributed under the MIT software license, see the accompanying file LICENSE.md  */

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
            /*  Okay, here things go a bit ugly. Sorry about that. Since there's no good PHP library for Avvy Domains, we
             *  have to use a Python library. The recommended way is to set up this: https://github.com/avvydomains/python-client
             *  and set up a proxy for it. Here's how can the Python part (`resolve.py`) look like:
             *
             *  from avvy import AvvyClient
             *  from web3 import Web3
             *  import sys
             *  w3 = Web3(Web3.HTTPProvider('...'))
             *  avvy = AvvyClient(w3)
             *  evm_address = avvy.name(sys.argv[1]).resolve(avvy.RECORDS.EVM)
             *  print(f'{evm_address}')
             *
             *  And the PHP part (`index.php`):
             *
             *  <?php if (!isset($_GET['name'])) return;
             *  $resolves_to = trim(`python3 resolve.py {$_GET['name']}`);
             *  if (!$resolves_to || $resolves_to === 'None') return;
             *  echo '{"result": "' . strtolower($resolves_to) . '"}';
             *
             *  Then, simply point an nginx proxy to `index.php`, and add this proxy to `HANDLE_NODES`
             *
             *  Once there's a better PHP support or some on-chain contracts to resolve names, we'll switch to this.
             */

            if (!preg_match($this->handles_regex, $handle))
                return null;

            $handle_nodes = envm($this->module, 'HANDLE_NODES');
            $handle_node = $handle_nodes[array_rand($handle_nodes)];

            try
            {
                $address = requester_single(daemon: $handle_node, endpoint: '?name=' . $handle, result_in: 'result');
                return $address;
            }
            catch (RequesterEmptyResponseException)
            {
                return null;
            }
        };
    }
}
