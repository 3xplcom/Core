<?php declare(strict_types=1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the main Tron module. It requires java-tron node to run with the following PR https://github.com/tronprotocol/java-tron/pull/5469 */

final class TronMainModule extends TVMMainModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'tron';
        $this->module = 'tron-main';
        $this->is_main = true;
        $this->first_block_id = 0;
        $this->first_block_date = '2018-06-25';
        $this->currency = 'tron';
        $this->currency_details = ['name' => 'TRON', 'symbol' => 'TRX', 'decimals' => 6, 'description' => null];

        // TVMMainModule
        $this->extra_features = [TVMSpecialFeatures::AllowEmptyRecipient];
        $this->extra_data_details = array_flip(TVMSpecialTransactions::to_assoc_array());
        $this->reward_function = function ($block_id) {
            $sr_reward = '0';
            $partners_reward = '0';
            if ($block_id >= 0 && $block_id <= 14_228_705) {
                $sr_reward = '32000000';
                $partners_reward = '16000000';
            } elseif ($block_id > 14_228_705) {
                $sr_reward = '16000000';
                $partners_reward = '160000000';
            }
            return [$sr_reward, $partners_reward];
        };

        // Handles
        $this->handles_implemented = true;
        $this->handles_regex = '/(.*)\.(trx|tron|tns|usdd)/'; // https://forum.trondao.org/t/tns-domains-trx-name-services/16921
        $this->api_get_handle = function ($handle)
        {
            if (!preg_match($this->handles_regex, $handle))
                return null;

            require_once __DIR__ . '/../Engine/Crypto/Keccak.php';

            $hash = $this->ens_name_to_hash($handle);

            if (is_null($hash) || $hash === '')
                return null;

            $resolver = $this->ens_get_data($hash, '0xc677966d', '0xa209893e28339d8aa2fd3454dc322151c502947e');
            $address = $this->ens_get_data_from_resolver($resolver, $hash, '0x3b3b57de', -40);
            $res = null;
            if (strlen($address) == 40)
            {
                try
                {
                    $res = $this->encode_address_to_base58("0x" . $address);
                }
                catch (Exception)
                {
                    $res = null;
                }

            }
            return $res;
        };
    }
}
