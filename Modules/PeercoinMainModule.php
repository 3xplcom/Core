<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

final class PeercoinMainModule extends UTXOMainModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'peercoin';
        $this->module = 'peercoin-main';
        $this->is_main = true;
        $this->currency = 'peercoin';
        $this->currency_details = ['name' => 'Peercoin', 'symbol' => 'PPC', 'decimals' => 6, 'description' => null];
        $this->first_block_date = '2012-08-16';

        // UTXOMainModule
        $this->extra_features = [UTXOSpecialFeatures::Not8Decimals, UTXOSpecialFeatures::OneAddressInScriptPubKey];
        $this->p2pk_prefix1 = '';
        $this->p2pk_prefix2 = '37';
    }
}
