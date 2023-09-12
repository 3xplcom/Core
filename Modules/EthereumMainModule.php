<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
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
        $this->extra_features = [EVMSpecialFeatures::HasOrHadUncles, EVMSpecialFeatures::PoSWithdrawals];
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
    }
}
