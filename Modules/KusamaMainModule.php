<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the main Kusama module. */

final class KusamaMainModule extends SubstrateMainModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'kusama';
        $this->module = 'kusama-main';
        $this->is_main = true;
        $this->first_block_date = '2019-11-28';
        $this->currency = 'kusama';
        $this->currency_details = ['name' => 'Kusama', 'symbol' => 'KSM', 'decimals' => 12, 'description' => null];

        // Substrait-specific
        $this->chain_type = SubstrateChainType::Relay;
    }
}
