<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the main Bitcoin Cash module. It requires Bitcoin Cash Node (https://gitlab.com/bitcoin-cash-node/bitcoin-cash-node)
 *  with `txindex` set to true to run.  */

final class BitcoinCashMainModule extends UTXOMainModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'bitcoin-cash';
        $this->module = 'bitcoin-cash-main';
        $this->is_main = true;
        $this->currency = 'bitcoin-cash'; // Static
        $this->currency_details = ['name' => 'Bitcoin Cash', 'symbol' => 'BCH', 'decimals' => 8, 'description' => null];
        $this->first_block_date = '2009-01-03';

        // UTXOMainModule
        $this->extra_features = [UTXOSpecialFeatures::HasAddressPrefixes, UTXOSpecialFeatures::IgnorePubKeyConversion];
        $this->p2pk_prefix1 = '';
        $this->p2pk_prefix2 = '';
    }
}
