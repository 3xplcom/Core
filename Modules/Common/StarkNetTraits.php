<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  Common functions for StarkNet  */

trait StarkNetTraits
{
    public function inquire_latest_block()
    {
        return (int)requester_single($this->select_node(), 
        params: ['jsonrpc' => '2.0', 'method' => 'starknet_blockNumber', 'id' => 0], result_in: 'result', timeout: $this->timeout);
    }

    public function ensure_block($block_id, $break_on_first = false)
    {
        if ($block_id === MEMPOOL)
        {
            $this->block_hash = null;
            return true;
        }

        $multi_curl = [];

        $params = ['jsonrpc'=> '2.0', 'method' => 'starknet_getBlockWithTxHashes', 'params' => [['block_number' => $block_id]], 'id' => 0];

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

        $this->block_hash = $result0['block_hash'];
        $this->block_time = date('Y-m-d H:i:s', (int)$result0['timestamp']);

        if (count($curl_results) > 1)
        {
            foreach ($curl_results as $result)
            {
                if (requester_multi_process($result, result_in: 'result')['block_hash'] !== $this->block_hash)
                {
                    throw new ConsensusException("ensure_block(block_id: {$block_id}): no consensus");
                }
            }
        }
    }
}
