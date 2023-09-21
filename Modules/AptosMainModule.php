<?php declare(strict_types=1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes main Aptos transfers.  */

final class AptosMainModule extends AptosLikeMainModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'aptos';
        $this->module = 'aptos-main';
        $this->is_main = true;
        $this->currency = 'aptos';
        $this->currency_details = ['name' => 'APT', 'symbol' => 'APT', 'decimals' => 8, 'description' => null];
        $this->first_block_date = '2022-10-12';
        $this->first_block_id = 0;

        // Handles (AptosNames)
        $this->handles_implemented = true;
        $this->handles_regex = '/(.*)\.apt/';
        $this->api_get_handle = function ($handle) {
            if (!preg_match($this->handles_regex, $handle))
                return null;
            $handle = str_replace('.apt/', '', $handle);

            // SC have two views: get_targer_addr and get_owner_addr, right now we use only get_targer_addr to resolve.
            // https://explorer.aptoslabs.com/account/0x867ed1f6bf916171b1de3ee92849b8978b7d1b9e0a8cc982a3d19d535dfd9c0c/modules/view/router/get_target_addr?network=mainnet
            $aptos_names_sc = '0x867ed1f6bf916171b1de3ee92849b8978b7d1b9e0a8cc982a3d19d535dfd9c0c';
            $function = $aptos_names_sc . '::router::get_target_addr';
            $resp = requester_single(
                $this->select_node(),
                endpoint: "v1/view",
                params: [
                    'function' => $function,
                    'type_arguments' => [],
                    'arguments' => [$handle, ['vec' => []]]
                ],
                timeout: $this->timeout
            );

            return $resp[0]['vec'][0] ?? null;
        };
    }
}