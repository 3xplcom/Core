<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  This is the main Handshake module. It requires an HSD node (https://github.com/handshake-org/hsd)
 *  with `index-tx` set to 1 to run.  */

final class HandshakeMainModule extends HandshakeLikeMainModule implements Module
{
    function initialize()
    {
        // CoreModule
        $this->blockchain = 'handshake';
        $this->module = 'handshake-main';
        $this->is_main = true;
        $this->currency = 'handshake';
        $this->currency_details = ['name' => 'Handshake', 'symbol' => 'HNS', 'decimals' => 6, 'description' => null];
        $this->first_block_date = '2020-02-03';

        // Handles
        $this->handles_implemented = true;
        $this->handles_regex = '/(.*)\//';
        $this->api_get_handle = function($handle)
        {
            if (!preg_match($this->handles_regex, $handle))
                return null;

            $handle = substr($handle, 0, -1); // Removes the trailing slash

            try
            {
                $owner = requester_single($this->select_node(),
                    params: ['method' => 'getnameinfo', 'params' => [$handle]],
                    result_in: 'result',
                    timeout: $this->timeout)['info']['owner'] ?? null;
            }
            catch (RequesterException $e)
            {
                if (str_contains($e->getMessage(), 'Invalid name'))
                    return null;
                else
                    throw $e;
            }

            if ($owner && $owner['hash'] !== '0000000000000000000000000000000000000000000000000000000000000000')
            {
                $address = requester_single($this->select_node(),
                    endpoint: "tx/{$owner['hash']}",
                    timeout: $this->timeout);

                return $address['outputs'][($owner['index'])]['address'];
            }
            else
            {
                return null;
            }
        };
    }
}
