<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023-2024 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the main eCash module. It requires a Bitcoin ABC node to run (https://github.com/Bitcoin-ABC/bitcoin-abc)
 *  with `txindex` set to true to run.  */

final class eCashMainModule extends UTXOMainModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'ecash';
        $this->module = 'ecash-main';
        $this->is_main = true;
        $this->currency = 'ecash';
        $this->currency_details = ['name' => 'eCash', 'symbol' => 'XEC', 'decimals' => 2, 'description' => null];
        $this->first_block_date = '2009-01-03';

        // UTXOMainModule
        $this->extra_features = [UTXOSpecialFeatures::HasAddressPrefixes,
                                 UTXOSpecialFeatures::ManualCashAddress,
                                 UTXOSpecialFeatures::Not8Decimals];
        $this->p2pk_prefix1 = '1';
        $this->p2pk_prefix2 = '00';
    }
}
