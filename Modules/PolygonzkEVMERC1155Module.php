<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This module processes ERC-1155 MT transfers in Polygon zkEVM. It requires a geth node to run.  */

final class PolygonzkEVMERC1155Module extends EVMERC1155Module implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'polygon-zkevm';
        $this->module = 'polygon-zkevm-erc-1155';
        $this->is_main = false;
        $this->first_block_date = '2023-03-24';
        $this->first_block_id = 0;
        $this->mempool_implemented = true;

        // EVMTraceModule
        $this->extra_features = [EVMSpecialFeatures::zkEVM];

        // Tests
        $this->tests = [
            ['block' => 1992116, 'result' => 'a:2:{s:6:"events";a:2:{i:0;a:8:{s:11:"transaction";s:66:"0xe7d6ae468f75462a4893975d077db97fef3a95bcbf817f9c8793c030ec017a80";s:8:"currency";s:42:"0xf57cb671d50535126694ce5cc3cebe3f32794896";s:7:"address";s:42:"0x0000000000000000000000000000000000000000";s:8:"sort_key";i:0;s:6:"effect";s:2:"-1";s:5:"extra";s:1:"7";s:5:"block";i:1992116;s:4:"time";s:19:"2024-02-23 09:07:47";}i:1;a:8:{s:11:"transaction";s:66:"0xe7d6ae468f75462a4893975d077db97fef3a95bcbf817f9c8793c030ec017a80";s:8:"currency";s:42:"0xf57cb671d50535126694ce5cc3cebe3f32794896";s:7:"address";s:42:"0xb11d48385e939e6eb8db5b88ee6b41692bb96f46";s:8:"sort_key";i:1;s:6:"effect";s:1:"1";s:5:"extra";s:1:"7";s:5:"block";i:1992116;s:4:"time";s:19:"2024-02-23 09:07:47";}}s:10:"currencies";a:1:{i:0;a:3:{s:2:"id";s:42:"0xf57cb671d50535126694ce5cc3cebe3f32794896";s:4:"name";s:15:"Rubyscore_zkEVM";s:6:"symbol";s:15:"Rubyscore_zkEVM";}}}']
        ];
    }
}
