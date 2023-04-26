<?php declare(strict_types = 1);

/*  Copyright (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com
 *  Distributed under the MIT software license, see the accompanying file LICENSE.md  */

/*  This is a module for Polkadot. See PolkadotLikeMinimalModule.php for details.  */

final class PolkadotMinimalModule extends PolkadotLikeMinimalModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'polkadot';
        $this->module = 'polkadot-minimal';
        $this->is_main = true;
        $this->currency = 'polkadot';
        $this->currency_details = ['name' => 'DOT', 'symbol' => 'DOT', 'decimals' => 10, 'description' => null];
        $this->first_block_date = '2020-05-26';
        $this->first_block_id = 0;
    }
}
