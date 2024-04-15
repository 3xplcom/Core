<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023-2024 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the main Ethereum module. It requires either a geth or an Erigon node to run (but the latter is much faster).  */

final class EthereumMainModule extends EVMMainModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'ethereum';
        $this->module = 'ethereum-main';
        $this->is_main = true;
        $this->first_block_date = '2015-07-30';
        $this->currency = 'ethereum';
        $this->currency_details = ['name' => 'Ethereum', 'symbol' => 'ETH', 'decimals' => 18, 'description' => null];

        // EVMMainModule
        $this->evm_implementation = EVMImplementation::Erigon; // Change to geth if you're running geth, but this would be slower
        $this->extra_features = [EVMSpecialFeatures::HasOrHadUncles, EVMSpecialFeatures::PoSWithdrawals, EVMSpecialFeatures::EIP4844];
        $this->staking_contract = '0x00000000219ab540356cbb839cbe05303d7705fa';
        $this->reward_function = function($block_id)
        {
            if ($block_id >= 0 && $block_id <= 4_369_999)
            {
                $base_reward = '5000000000000000000';
            }
            elseif ($block_id >= 4_370_000 && $block_id <= 7_279_999)
            {
                $base_reward = '3000000000000000000';
            }
            elseif ($block_id >= 7_280_000 && $block_id <= 15_537_393)
            {
                $base_reward = '2000000000000000000';
            }
            elseif ($block_id >= 15_537_394) // The Merge
            {
                $base_reward = '0';
            }

            return $base_reward;
        };

        // Handles
        $this->handles_implemented = true;
        $this->handles_regex = '/(.*)\.eth/';
        $this->api_get_handle = function($handle)
        {
            if (!preg_match($this->handles_regex, $handle))
                return null;

            require_once __DIR__ . '/../Engine/Crypto/Keccak.php';

            $hash = $this->ens_name_to_hash($handle);

            if (is_null($hash) || $hash === '')
                return null;

            $resolver = $this->ens_get_data($hash, '0x0178b8bf', '0x00000000000c2e074ec69a0dfb2997ba6c7d2e1e');
            $address = $this->ens_get_data_from_resolver($resolver, $hash, '0x3b3b57de', -40);

            if ($address)
                return '0x' . $address;
            else
                return null;
        };

        // Tests
        $this->tests = [
            // Blob transaction
            ['block' => 19429224, 'transaction' => "0xe876dc9e3e2a87fff5f43e9072f8046a0a46d2a2276f01bbb0a69ae0af6af4fa", "result" => 'a:1:{s:6:"events";a:6:{i:0;a:8:{s:11:"transaction";s:66:"0xe876dc9e3e2a87fff5f43e9072f8046a0a46d2a2276f01bbb0a69ae0af6af4fa";s:7:"address";s:42:"0x2c169dfe5fbba12957bdd0ba47d9cedbfe260ca7";s:6:"effect";s:17:"-8079000539885872";s:6:"failed";s:1:"f";s:5:"extra";s:1:"b";s:5:"block";i:19429224;s:4:"time";s:19:"2024-03-13 22:52:47";s:8:"sort_key";i:666;}i:1;a:8:{s:11:"transaction";s:66:"0xe876dc9e3e2a87fff5f43e9072f8046a0a46d2a2276f01bbb0a69ae0af6af4fa";s:7:"address";s:4:"0x00";s:6:"effect";s:16:"8079000539885872";s:6:"failed";s:1:"f";s:5:"extra";s:1:"b";s:5:"block";i:19429224;s:4:"time";s:19:"2024-03-13 22:52:47";s:8:"sort_key";i:667;}i:2;a:8:{s:11:"transaction";s:66:"0xe876dc9e3e2a87fff5f43e9072f8046a0a46d2a2276f01bbb0a69ae0af6af4fa";s:7:"address";s:42:"0x2c169dfe5fbba12957bdd0ba47d9cedbfe260ca7";s:6:"effect";s:15:"-13660000000000";s:6:"failed";s:1:"f";s:5:"extra";s:1:"f";s:5:"block";i:19429224;s:4:"time";s:19:"2024-03-13 22:52:47";s:8:"sort_key";i:668;}i:3;a:8:{s:11:"transaction";s:66:"0xe876dc9e3e2a87fff5f43e9072f8046a0a46d2a2276f01bbb0a69ae0af6af4fa";s:7:"address";s:42:"0x88c6c46ebf353a52bdbab708c23d0c81daa8134a";s:6:"effect";s:14:"13660000000000";s:6:"failed";s:1:"f";s:5:"extra";s:1:"f";s:5:"block";i:19429224;s:4:"time";s:19:"2024-03-13 22:52:47";s:8:"sort_key";i:669;}i:4;a:8:{s:11:"transaction";s:66:"0xe876dc9e3e2a87fff5f43e9072f8046a0a46d2a2276f01bbb0a69ae0af6af4fa";s:7:"address";s:42:"0x2c169dfe5fbba12957bdd0ba47d9cedbfe260ca7";s:6:"effect";s:2:"-0";s:6:"failed";s:1:"f";s:5:"extra";N;s:5:"block";i:19429224;s:4:"time";s:19:"2024-03-13 22:52:47";s:8:"sort_key";i:670;}i:5;a:8:{s:11:"transaction";s:66:"0xe876dc9e3e2a87fff5f43e9072f8046a0a46d2a2276f01bbb0a69ae0af6af4fa";s:7:"address";s:42:"0xc662c410c0ecf747543f5ba90660f6abebd9c8c4";s:6:"effect";s:1:"0";s:6:"failed";s:1:"f";s:5:"extra";N;s:5:"block";i:19429224;s:4:"time";s:19:"2024-03-13 22:52:47";s:8:"sort_key";i:671;}}}']
        ];
    }
}
