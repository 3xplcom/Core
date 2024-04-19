<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  Common functions for TON modules (JettonModule, NFJettonModule)  */


trait TONTraits
{
    public function inquire_latest_block()
    {
        $result = requester_single($this->select_node(), endpoint: 'lastNum', timeout: $this->timeout);

        return (int)$result[($this->workchain)][(array_key_first($result[($this->workchain)]))]['seqno'];
        // This may be not the best solution in case there are several shard with different heights
    }

    public function ensure_block($block_id, $break_on_first = false)
    {
        if ($block_id === 0) // Block #0 is there, but the node doesn't return data for it
        {
            $this->block_hash = "";
            return;
        }

        $multi_curl = [];

        foreach ($this->nodes as $node)
        {
            $multi_curl[] = requester_multi_prepare($node, endpoint: "getHashByHeight?workchain={$this->workchain}&seqno={$block_id}", timeout: $this->timeout);

            if ($break_on_first)
                break;
        }

        try
        {
            $curl_results = requester_multi($multi_curl, limit: count($this->nodes), timeout: $this->timeout);
        }
        catch (RequesterException $e)
        {
            throw new RequesterException("ensure_block(block_id: {$block_id}): no connection, previously: " . $e->getMessage());
        }

        $hashes = requester_multi_process($curl_results[0]);
        ksort($hashes, SORT_STRING);

        $shard_list = $final_filehash = [];

        foreach ($hashes as $shard => $shard_hashes)
        {
            $shard_list[] = $shard;
            $final_filehash[] = $shard_hashes['filehash'];

            $this->shards[$shard] = ['filehash' => $shard_hashes['filehash'], 'roothash' => $shard_hashes['roothash']];
        }

        $this->block_hash = strtolower(implode($final_filehash));

        $this->block_extra = strtolower(implode('/', $shard_list));

        if (count($curl_results) > 0)
        {
            foreach ($curl_results as $result)
            {
                $this_hashes = requester_multi_process($result);
                ksort($this_hashes, SORT_STRING);

                $this_final_filehash = [];

                foreach ($this_hashes as $shard => $shard_hashes)
                {
                    $this_final_filehash[] = $shard_hashes['filehash'];
                }

                if (strtolower(implode($this_final_filehash)) !== $this->block_hash)
                {
                    throw new ConsensusException("ensure_block(block_id: {$block_id}): no consensus");
                }
            }
        }
    }
}
