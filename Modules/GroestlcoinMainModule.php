<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the main Groestlcoin module. It requires Grostlcoin Core (https://github.com/Groestlcoin/groestlcoin)
 *  with `txindex` set to true to run. Note that for correct processing of P2PK outputs this should be reverted:
 *  https://github.com/bitcoin/bitcoin/pull/16725/files and the ExtractDestination() function itself should return
 *  `true` for `PUBKEY` like this: ```CPubKey pubKey(vSolutions[0]); if (!pubKey.IsValid()) return false;
 *  addressRet = PKHash(pubKey); return true;```  */

final class GroestlcoinMainModule extends UTXOMainModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'groestlcoin';
        $this->module = 'groestlcoin-main';
        $this->is_main = true;
        $this->currency = 'groestlcoin'; // Static
        $this->currency_details = ['name' => 'Groestlcoin', 'symbol' => 'GRS', 'decimals' => 8, 'description' => null];
        $this->first_block_date = '2014-03-20';

        // UTXOMainModule
        $this->extra_features = [UTXOSpecialFeatures::OneAddressInScriptPubKey, UTXOSpecialFeatures::IgnorePubKeyConversion];
        $this->p2pk_prefix1 = '';
        $this->p2pk_prefix2 = '24';
    }
}
