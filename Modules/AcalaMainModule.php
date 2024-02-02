<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the main Acala module. */

final class AcalaMainModule extends SubstrateMainModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'acala';
        $this->module = 'acala-main';
        $this->is_main = true;
        $this->first_block_date = '2021-12-18';
        $this->currency = 'acala';
        $this->currency_details = ['name' => 'Acala', 'symbol' => 'ACA', 'decimals' => 12, 'description' => null];

        // Substrate-specific
        $this->chain_type = SubstrateChainType::Para;
    }
}
