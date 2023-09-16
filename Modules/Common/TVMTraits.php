<?php

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  Common TVM functions and enums  */
require_once __DIR__ . '/../../Engine/Crypto/Base58.php';

enum TVMSpecialFeatures
{
    case AllowEmptyRecipient;
}

enum TVMSpecialTransactions: string
{
//    https://github.com/tronprotocol/java-tron/blob/ad728fa64ef34857efecee59307cd6483558a025/protocol/src/main/protos/core/Tron.proto#L338
    case AccountCreateContract = 'ac'; // 51834140, 42204553
    case TransferContract = 't'; // simple trx transfer happens in almost every block, 51834140
    case TransferAssetContract = 'ta'; // 51834140, 9999976 - internal transfer
    case VoteAssetContract = 'va'; // 52933472
    case VoteWitnessContract = 'vw'; // 52933472
    case WitnessCreateContract = 'wc'; // 42916
    case AssetIssueContract = 'ai'; // 5952467
    case WitnessUpdateContract = 'wu';
    case ParticipateAssetIssueContract = 'pai'; //5865822 investigate
    case AccountUpdateContract = 'au'; // 	1137488
    case FreezeBalanceContract = 'fb'; // 3729331, 2142
    case UnfreezeBalanceContract = 'ub'; // 9503734, 54629027
    case WithdrawBalanceContract = 'wb'; // 52273501,602349
    case UnfreezeAssetContract = 'ufa';
    case UpdateAssetContract = 'ua';
    case ProposalCreateContract = 'pc'; //   53414318
    case ProposalApproveContract = 'pa';
    case ProposalDeleteContract = 'pd';
    case SetAccountIdContract = 'sai'; // 5092972
    case CustomContract = 'cc';
    case CreateSmartContract = 'csc';
    case TriggerSmartContract = 'tsc'; // 7811800
    case GetContract = 'g';
    case UpdateSettingContract = 'us';
    case ExchangeCreateContract = 'ec'; // 51834140, 4067933
    case ExchangeInjectContract = 'ei'; // 51847942
    case ExchangeWithdrawContract = 'ew';
    case ExchangeTransactionContract = 'et'; // 4058495, 4067933, 6328438
    case UpdateEnergyLimitContract = 'uel';
    case AccountPermissionUpdateContract = 'apu'; // 51834140s
    case ClearABIContract = 'cabi';
    case UpdateBrokerageContract = 'upb';
    case ShieldedTransferContract = 'st';
    case MarketSellAssetContract = 'msa';
    case MarketCancelOrderContract = 'mco';
    case FreezeBalanceV2Contract = 'fbv2';
    case UnfreezeBalanceV2Contract = 'ubv2'; // 54628671
    case WithdrawExpireUnfreezeContract = 'weuf'; // 51101981, 54641051
    case DelegateResourceContract = 'dr'; // 52204553
    case UnDelegateResourceContract = 'udr'; // 52204553
    case CancelAllUnfreezeV2Contract = 'caufv2'; // 54615914

    // 3xpl specific
    case Burning = 'b';
    case BlockReward = 'r';
    case PartnerReward = 'pr';

    public static function fromName(string $name): string
    {
        foreach (self::cases() as $status) {
            if ($name === $status->name) {
                return $status->value;
            }
        }
        throw new ValueError("New contract type $name investigate the logic" . self::class);
    }

    public static function to_assoc_array(): array
    {
        $result = [];
        foreach (self::cases() as $status) {
            $result[$status->name] = $status->value;
        }
        return $result;
    }
}

trait TVMTraits
{
    public function inquire_latest_block()
    {
        return to_int64_from_0xhex(requester_single($this->select_node(),
            params: ['jsonrpc' => '2.0', 'method' => 'eth_blockNumber', 'id' => 0], result_in: 'result', timeout: $this->timeout));
    }

    public function ensure_block($block_id, $break_on_first = false)
    {
        if ($block_id === MEMPOOL)
        {
            $this->block_hash = null;
            return true;
        }

        $multi_curl = [];
        $params = ['jsonrpc' => '2.0', 'method' => 'eth_getBlockByNumber', 'params' => [to_0xhex_from_int64($block_id), false], 'id' => 0];

        foreach ($this->nodes as $node)
        {
            $multi_curl[] = requester_multi_prepare($node, params: $params, timeout: $this->timeout);
            if ($break_on_first) break;
        }

        try
        {
            $curl_results = requester_multi($multi_curl, limit: count($this->nodes), timeout: $this->timeout);
        }
        catch (RequesterException $e)
        {
            throw new RequesterException("ensure_block(block_id: {$block_id}): no connection, previously: " . $e->getMessage());
        }

        $result0 = requester_multi_process($curl_results[0], result_in: 'result');


        $this->block_hash = $result0['hash'];
        $this->block_time = date('Y-m-d H:i:s', to_int64_from_0xhex($result0['timestamp']));

        if (count($curl_results) > 1)
        {
            foreach ($curl_results as $result)
            {
                if (requester_multi_process($result, result_in: 'result')['hash'] !== $this->block_hash)
                {
                    throw new ConsensusException("ensure_block(block_id: {$block_id}): no consensus");
                }
            }
        }

        $this->block_hash = remove_0x_safely($this->block_hash);
    }

    /**
     * Encodes 0x... address to Base58
     * in other cases returns the result as is
     * 0xA614F803B6FD780986A42C78EC9C7F77E6DED13C -> TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t
     * @param string|null $address
     * @return string|null
     */
    public function encode_address_to_base58(string|null $address): string|null
    {
        if (!is_null($address) && str_starts_with($address, "0x"))
        {
            return Base58::hex_to_base58_check("41" . substr($address, 2));
        }
        return $address;
    }

    /**
     * Encodes Base58 address to evm compatible hex
     * in other cases returns the result as is
     *  TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t -> A614F803B6FD780986A42C78EC9C7F77E6DED13C
     * @param string|null $address
     * @return string|null
     *
     */
    public function encode_base58_to_evm_hex(string|null $address): string|null
    {
        if (!is_null($address))
        {
            return substr(Base58::base58_check_to_hex($address), 2);
        }
        return $address;
    }

    public function get_exchange_by_id(string|int $id): array|null
    {
        $exchange = requester_single($this->select_node(),
            endpoint: "/wallet/getexchangebyid?id=$id", timeout: $this->timeout);
        $exchange['has_trx'] = ($exchange['first_token_id'] === '5f') || ($exchange['second_token_id'] === '5f');
        return $exchange;
    }

    function encode_abi(string $flag, string|array $data): string
    {
        switch ($flag)
        {
            case 'string':
                $length = str_repeat('0', (64 - (strlen(dec2hex((string)strlen($data))) % 64))) . dec2hex((string)strlen(($data)));
                $string = bin2hex($data) . str_repeat('0', 64 - (strlen(bin2hex($data)) % 64));
                $result = str_repeat('0', 62) . '40' . $length . $string;
                break;
            case 'string,uint256':
                $length = str_repeat('0', (64 - (strlen(dec2hex((string)strlen($data[0]))) % 64))) . dec2hex((string)strlen(($data[0])));
                $string = bin2hex($data[0]) . str_repeat('0', 64 - (strlen(bin2hex($data[0])) % 64));
                $result = str_repeat('0', 62) . '40' . str_repeat('0', (64 - strlen(dec2hex($data[1])) % 64)) . dec2hex($data[1]) . $length . $string;
                break;
            case 'uint256':
                $result = str_repeat('0', (64 - (strlen(dec2hex($data))) % 64)) . dec2hex($data);
                break;
            case 'address':
                $result = str_repeat('0', (64 - strlen($data))) . $data;
                break;
            case 'address[]':
                $result = str_repeat('0', 64 - strlen(dec2hex((string)count($data)))) . dec2hex(count($data));
                foreach ($data as $address)
                    $result .= (str_repeat('0', (64 - strlen($address))) . $address);
                break;
            case 'address,address[]':
                if (count($data) !== 2)
                    throw new DeveloperError('Error number of addresses given to encode_abi function');
                $result = str_repeat('0', (64 - strlen($data[0]))) . $data[0]; //encode address to be parsed
                $result .= str_repeat('0', 62) . '40'; //encode the position where to start read array
                $result .= str_repeat('0', 64 - strlen(dec2hex((string)count($data[1])))) . dec2hex((string)count($data[1])); //encode number of elements in array
                foreach ($data[1] as $address)
                    $result .= str_repeat('0', (64 - strlen($address))) . $address;
                break;
            default:
                throw new DeveloperError('Unknown flag');
        }

        return $result;
    }

    function ens_label_to_hash($label)
    {
        // All ENS names should conform to the IDNA standard UTS #46 including STD3 Rules, see
        // http://unicode.org/reports/tr46/

        $label = idn_to_ascii($label, IDNA_USE_STD3_RULES, INTL_IDNA_VARIANT_UTS46);

        if (str_contains($label,'.'))
            return null;

        return Keccak9::hash($label, 256);
    }

    function ens_name_to_hash($name)
    {
        $node = '0000000000000000000000000000000000000000000000000000000000000000';

        if (!is_null($name) && (strlen($name) > 0))
        {
            $labels = explode('.', $name);

            foreach (array_reverse($labels) as $label)
            {
                $label_hash = $this->ens_label_to_hash($label);

                if (is_null($label_hash))
                    return null;

                $node = $node . $label_hash;
                $node = Keccak9::hash(hex2bin($node), 256);
            }
        }
        else
        {
            return null;
        }

        return $node;
    }

    function ens_get_data($hash, $function, $registry_contract)
    {
        $output = requester_single($this->select_node(),
            params: ['jsonrpc' => '2.0',
                'method'  => 'eth_call',
                'id'      => 0,
                'params'  => [['to'   => $registry_contract,
                    'data' => $function . $this->encode_abi('address', $hash),
                ],
                    'latest',
                ],
            ],
            result_in: 'result',
            timeout: $this->timeout);

        return '0x' . substr($output, -40);
    }

    function ens_get_data_from_resolver($resolver, $hash, $function, $length = 0)
    {
        if ($resolver === '0x0000000000000000000000000000000000000000')
            return null;

        $output = requester_single($this->select_node(),
            params: ['jsonrpc' => '2.0',
                'method'  => 'eth_call',
                'id'      => 0,
                'params'  => [['to'   => $resolver,
                    'data' => $function . $this->encode_abi('address', $hash),
                ],
                    'latest',
                ],
            ],
            result_in: 'result',
            timeout: $this->timeout);

        return str_replace('0x', '', substr($output, $length));
    }
}

