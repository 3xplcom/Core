<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  Common Aptos functions and enums  */

enum AptosSpecialTransactions: string
{
    case ValidatorReward = 'r';
    case Fee = 'f';
}

trait AptosTraits
{
    public function inquire_latest_block()
    {
        $result = requester_single($this->select_node(), endpoint: 'v1', timeout: $this->timeout);
        return (int) $result["block_height"];
    }

    public function ensure_block($block_id, $break_on_first = false)
    {
        $multi_curl = [];
        foreach ($this->nodes as $node)
        {
            $multi_curl[] = requester_multi_prepare($node, endpoint: "v1/blocks/by_height/{$block_id}", timeout: $this->timeout);
        }

        try
        {
            $curl_results = requester_multi($multi_curl, limit: count($this->nodes), timeout: $this->timeout);
        } catch (RequesterException $e)
        {
            throw new RequesterException("ensure_block(block_id: {$block_id}): no connection, previously: " . $e->getMessage());
        }

        if (count($curl_results) !== 0)
        {
            $this->block_hash = requester_multi_process($curl_results[0], result_in: "block_hash");
            foreach ($curl_results as $curl_result)
            {
                if (requester_multi_process($curl_result, result_in: "block_hash") !== $this->block_hash)
                {
                    throw new ConsensusException("ensure_block(block_id: {$block_id}): no consensus");
                }
            }
        }
    }

    function try_convert_hex(string $str): string
    {
        if (str_starts_with($str, '0x'))
        {
            return hex2dec(substr($str, 2));
        }
        return $str;
    }
}
