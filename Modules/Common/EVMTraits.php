<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  Common EVM functions and enums  */

enum EVMImplementation: string
{
    case geth = 'geth';
    case Erigon = 'Erigon';
}

enum EVMSpecialTransactions: string
{
    case Burning = 'b';
    case FeeToMiner = 'f';
    case BlockReward = 'r';
    case UncleInclusionReward = 'i';
    case UncleReward = 'u';
    case ContractCreation = 'c';
    case ContractDestruction = 'd';
    case Withdrawal = 'w';
}

enum EVMSpecialFeatures
{
    case HasOrHadUncles;
    case BorValidator;
    case AllowEmptyRecipient;
    case PoSWithdrawals;
    case zkEVM;
    case HasSystemTransactions;
    case EffectiveGasPriceCanBeZero;
}

trait EVMTraits
{
    public function inquire_latest_block()
    {
        $method = (!in_array(EVMSpecialFeatures::zkEVM, $this->extra_features))
            ? 'eth_blockNumber'
            : 'zkevm_virtualBatchNumber';

        return to_int64_from_0xhex(requester_single($this->select_node(),
            params: ['jsonrpc'=> '2.0', 'method' => $method, 'id' => 0], result_in: 'result', timeout: $this->timeout));
    }

    public function ensure_block($block_id, $break_on_first = false)
    {
        if ($block_id === MEMPOOL)
        {
            $this->block_hash = null;
            return true;
        }

        $multi_curl = [];

        if (in_array(EVMSpecialFeatures::zkEVM, $this->extra_features))
        {
            $params = ['jsonrpc'=> '2.0', 'method' => 'zkevm_getBatchByNumber', 'params' => [to_0xhex_from_int64($block_id), false], 'id' => 0];
        }
        elseif (isset($this->evm_implementation) && $this->evm_implementation === EVMImplementation::Erigon)
        {
            $params = ['jsonrpc'=> '2.0', 'method' => 'erigon_getHeaderByNumber', 'params' => [to_0xhex_from_int64($block_id)], 'id' => 0];
        }
        else // geth
        {
            $params = ['jsonrpc'=> '2.0', 'method' => 'eth_getBlockByNumber', 'params' => [to_0xhex_from_int64($block_id), false], 'id' => 0];
        }

        $from_nodes = $this->fast_nodes ?? $this->nodes;

        foreach ($from_nodes as $node)
        {
            $multi_curl[] = requester_multi_prepare($node, params: $params, timeout: $this->timeout);
            if ($break_on_first) break;
        }

        try
        {
            $curl_results = requester_multi($multi_curl, limit: count($from_nodes), timeout: $this->timeout);
        }
        catch (RequesterException $e)
        {
            throw new RequesterException("ensure_block(block_id: {$block_id}): no connection, previously: " . $e->getMessage());
        }

        $result0 = requester_multi_process($curl_results[0], result_in: 'result');

        $hash_key = (!in_array(EVMSpecialFeatures::zkEVM, $this->extra_features))
            ? 'hash'
            : 'sendSequencesTxHash';

        $this->block_hash = $result0[$hash_key];
        $this->block_time = date('Y-m-d H:i:s', to_int64_from_0xhex($result0['timestamp']));

        if (count($curl_results) > 1)
        {
            foreach ($curl_results as $result)
            {
                if (requester_multi_process($result, result_in: 'result')[$hash_key] !== $this->block_hash)
                {
                    throw new ConsensusException("ensure_block(block_id: {$block_id}): no consensus");
                }
            }
        }
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

function evm_trace($calls, &$this_calls)
{
    foreach ($calls as $call)
    {
        if (!in_array($call['type'], ['CALL', 'STATICCALL', 'DELEGATECALL', 'CALLCODE', 'CREATE', 'CREATE2', 'SELFDESTRUCT', 'INVALID']))
            throw new ModuleError("Unknown call type: {$call['type']}");

        if ($call['type'] === 'INVALID' && isset($call['calls'])) // Check that INVALID calls don't have children
            throw new ModuleError('Invalid INVALID call');

        if (!in_array($call['type'], ['STATICCALL', 'DELEGATECALL', 'CALLCODE', 'INVALID']) && !isset($call['error']))
        { // We're not processing calls that don't transfer value, thus we ignore all these 4 types and errored calls
            if ($call['type'] !== 'CALL' || $call['value'] !== '0x0') // And we don't store CALLs with 0 value
            {
                $this_type = match ($call['type'])
                {
                    'CALL' => null,
                    'CREATE' => EVMSpecialTransactions::ContractCreation->value,
                    'CREATE2' => EVMSpecialTransactions::ContractCreation->value,
                    'SELFDESTRUCT' => EVMSpecialTransactions::ContractDestruction->value,
                };

                $this_calls[] = ['from'  => $call['from'],
                                 'to'    => $call['to'],
                                 'type'  => $this_type,
                                 'value' => to_int256_from_0xhex($call['value']),
                ];
            }
        }

        if (isset($call['calls']))
            evm_trace($call['calls'], $this_calls);
    }
}
