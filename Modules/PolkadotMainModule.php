<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the main Polkadot module. */

final class PolkadotMainModule extends SubstrateMainModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'polkadot';
        $this->module = 'polkadot-main';
        $this->is_main = true;
        $this->first_block_date = '2020-05-26';
        $this->currency = 'polkadot';
        // The denomination of DOT was changed from 12 decimals of precision at block #1,248,328 in an event known as Denomination Day
        $this->currency_details = ['name' => 'Polkadot', 'symbol' => 'DOT', 'decimals' => 10, 'description' => null];

        // Substrate-specific
        $this->chain_type = SubstrateChainType::Relay;
    }
}
