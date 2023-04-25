<?php declare(strict_types = 1);

/*  Copyright (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com
 *  Distributed under the MIT software license, see the accompanying file LICENSE.md  */

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
}

enum EVMSpecialFeatures
{
    case HasOrHadUncles;
}

trait EVMTraits
{
    public function inquire_latest_block()
    {
        return to_int64_from_0xhex(requester_single($this->select_node(),
            params: ['jsonrpc'=> '2.0', 'method' => 'eth_blockNumber', 'id' => 0], result_in: 'result', timeout: $this->timeout));
    }

    public function ensure_block($block_id, $break_on_first = false)
    {
        if ($block_id === MEMPOOL)
        {
            $this->block_hash = null;
            return true;
        }

        $multi_curl = [];

        if ($this->evm_implementation === EVMImplementation::Erigon)
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
    }

    function encode_abi(string $flag, string|array $data): string
    {
        switch ($flag)
        {
            case 'string':
                $length = str_repeat('0', (64 - strlen(dec2hex(strlen($data))))) . dec2hex(strlen(($data)));
                $string = bin2hex($data) . str_repeat('0', 64 - strlen(bin2hex($data)));
                $result = ('0000000000000000000000000000000000000000000000000000000000000040' . $length . $string);
                break;
            case 'uint256':
                $result = str_repeat('0', 64 - strlen(dec2hex($data))) . dec2hex($data);
                break;
            case 'address':
                $result = str_repeat('0', (64 - strlen($data))) . $data;
                break;
            case 'address[]':
                $result = str_repeat('0', 64 - strlen(dec2hex(count($data)))) . dec2hex(count($data));
                foreach ($data as $address)
                    $result .= (str_repeat('0', (64 - strlen($address))) . $address);
                break;
            case 'address,address[]':
                if (count($data) !== 2)
                    throw new DeveloperError('Error number of addresses given to encode_abi function');
                $result = str_repeat('0', (64 - strlen($data[0]))) . $data[0]; //encode address to be parsed
                $result .= str_repeat('0', 62) . '40'; //encode the position where to start read array
                $result .= str_repeat('0', 64 - strlen(dechex(count($data[1])))) . dechex(count($data[1])); //encode number of elements in array
                foreach ($data[1] as $address)
                    $result .= str_repeat('0', (64 - strlen($address))) . $address;
                break;
            default:
                throw new DeveloperError('Unknown flag');
        }

        return $result;
    }
}
