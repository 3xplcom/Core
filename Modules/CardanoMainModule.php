<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the main Cardano module which processes UTXO transfers only. See CardanoLikeMainModule for details.
 *  For resolving ADA Handle names it also requires this indexer: https://github.com/koralabs/handles-public-api  */

final class CardanoMainModule extends CardanoLikeMainModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'cardano';
        $this->module = 'cardano-main';
        $this->is_main = true;
        $this->currency = 'cardano';
        $this->currency_details = ['name' => 'Cardano', 'symbol' => 'ADA', 'decimals' => 6, 'description' => null];
        $this->first_block_id = 1;
        $this->first_block_date = '2017-09-23';

        // Handles
        $this->handles_implemented = true;
        $this->handles_regex = '/\$(.*)/';
        $this->api_get_handle = function($handle)
        {
            // Note that debugger should be called like this: `php 3xpl.php cardano-main H '$ada'` (with quotes around the name)

            if (!preg_match($this->handles_regex, $handle))
                return null;

            $handle_without_dollar = substr($handle, 1);

            $handle_nodes = envm($this->module, 'HANDLE_NODES');
            $handle_node = $handle_nodes[array_rand($handle_nodes)];

            $request = requester_single(daemon: $handle_node,
                endpoint: "handles/{$handle_without_dollar}",
                valid_codes: [200, 202, 404, 406, 451]); // 202 is valid and returned when the indexer is not fully synced

            if (!isset($request['name']) || $request['name'] !== $handle_without_dollar)
                return null;

            return $request['resolved_addresses']['ada'] ?? null; // There's also `holder_address` for the owner
        };
    }
}
