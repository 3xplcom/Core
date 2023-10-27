<?php declare(strict_types=1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  Common Cosmos SDK functions and enums  */

trait CosmosTraits
{
    public function inquire_latest_block()
    {
        $response = requester_single($this->select_node(), endpoint: 'status', timeout: $this->timeout);
        return (int) $response['result']['sync_info']['latest_block_height'];
    }

    public function ensure_block($block_id, $break_on_first = false)
    {
        $multi_curl = [];
        foreach ($this->nodes as $node)
        {
            $multi_curl[] = requester_multi_prepare($node, endpoint: "block?height={$block_id}", timeout: $this->timeout);
        }

        try
        {
            $curl_results = requester_multi($multi_curl, limit: count($this->nodes), timeout: $this->timeout);
        }
        catch (RequesterException $e)
        {
            throw new RequesterException("ensure_block(block_id: {$block_id}): no connection, previously: " . $e->getMessage());
        }

        if (count($curl_results) !== 0)
        {
            $result = requester_multi_process($curl_results[0], result_in: "result");
            $this->block_hash = $result['block_id']['hash'];
            $this->block_time = date('Y-m-d H:i:s', strtotime($result['block']['header']['time']));
            foreach ($curl_results as $curl_result)
            {
                if (requester_multi_process($curl_result, result_in: "result")['block_id']['hash'] !== $this->block_hash)
                {
                    throw new ConsensusException("ensure_block(block_id: {$block_id}): no consensus");
                }
            }
        }
    }

    // Converts tx_data from /block api into tx_hash
    function get_tx_hash(?string $tx_data): ?string
    {
        if (is_null($tx_data))
            return null;
        return hash('sha256', base64_decode($tx_data));
    }

    // Looking for fee info in tx events (fee in native coins)
    function try_find_fee_info(?array $tx_events): ?array
    {
        $fee_info = ['fee' => null, 'fee_payer' => null];
        if (count($tx_events) === 0)
            return $fee_info;

        foreach ($tx_events as $tx_event) {
            if ($tx_event['type'] === 'tx')
            {
                foreach ($tx_event['attributes'] as $attr)
                {
                    switch ($attr['key']) {
                        case 'ZmVl': // fee
                            if (is_null($attr['value'])) // zero fee for tx
                                $fee_info['fee'] = '0';
                            else
                                $fee_info['fee'] = $this->denom_amount_to_amount(base64_decode($attr['value']));
                            break;

                        case 'ZmVlX3BheWVy': // fee_payer
                            $fee_info['fee_payer'] = base64_decode($attr['value']);
                            break;

                        case 'YWNjX3NlcQ==': // acc_seq in format {addr}/{num}
                            $fee_info['fee_payer'] = explode('/', base64_decode($attr['value']))[0];
                            break;
                    }
                }
            }

            if (!is_null($fee_info['fee']) && !is_null($fee_info['fee_payer']))
                return $fee_info;
        }

        // Cannot find fee_info for none empty tx
        return null;
    }

    // Looking for fee info in tx events (in case fee is not in native coins)
    function try_find_ibc_fee_info(?array $tx_events): ?array
    {
        $fee_info = ['fee' => null, 'fee_payer' => null, 'fee_currency' => null];
        if (count($tx_events) === 0)
            return $fee_info;

        foreach ($tx_events as $tx_event) {
            if ($tx_event['type'] === 'tx')
            {
                foreach ($tx_event['attributes'] as $attr)
                {
                    switch ($attr['key']) {
                        case 'ZmVl': // fee
                            if (!is_null($attr['value']))
                            {
                                $ibc_amount = $this->denom_amount_to_ibc_amount(base64_decode($attr['value']));
                                if (!is_null($ibc_amount))
                                {
                                    $fee_info['fee'] = $ibc_amount['amount'];
                                    $fee_info['fee_currency'] = $ibc_amount['currency'];
                                }
                            }
                            break;

                        case 'ZmVlX3BheWVy': // fee_payer
                            $fee_info['fee_payer'] = base64_decode($attr['value']);
                            break;

                        case 'YWNjX3NlcQ==': // acc_seq in format {addr}/{num}
                            $fee_info['fee_payer'] = explode('/', base64_decode($attr['value']))[0];
                            break;
                    }
                }
            }

            if (!is_null($fee_info['fee']) && !is_null($fee_info['fee_payer']))
                return $fee_info;
        }

        // Cannot find fee_info for none empty tx
        return null;
    }

    // Returns null|array('from' => addr, 'amount' => string_array)
    function parse_coin_spent_event(?array $attributes): ?array
    {
        if (is_null($attributes))
            throw new ModuleException('Invalid `attributes` for coin_spent parsing (is null)!');

        $result = ['from' => null, 'amount' => null];
        foreach ($attributes as $attr)
        {
            if (is_null($attr['value']))
                return null;

            switch ($attr['key'])
            {
                case 'c3BlbmRlcg==': // spender
                    $result['from'] = base64_decode($attr['value']);
                    break;
                case 'YW1vdW50': // amount
                    $result['amount'] = explode(',', base64_decode($attr['value']));
                    break;
            }
        }

        return $result;
    }

    // Returns null|array('to' => addr, 'amount' => string_array)
    function parse_coin_received_event(?array $attributes): ?array
    {
        if (is_null($attributes))
            throw new ModuleException('Invalid `attributes` for coin_received parsing (is null)!');

        $result = ['to' => null, 'amount' => null];
        foreach ($attributes as $attr)
        {
            if (is_null($attr['value']))
                return null;

            switch ($attr['key'])
            {
                case 'cmVjZWl2ZXI=': // receiver
                    $result['to'] = base64_decode($attr['value']);
                    break;
                case 'YW1vdW50': // amount
                    $result['amount'] = explode(',', base64_decode($attr['value']));
                    break;
            }
        }

        return $result;
    }

    // Returns null|array('from' => addr, 'amount' => string_array)
    function parse_coinbase_event(?array $attributes): ?array
    {
        if (is_null($attributes))
            throw new ModuleException('Invalid `attributes` for coinbase parsing (is null)!');

        $result = ['from' => null, 'amount' => null];
        foreach ($attributes as $attr)
        {
            switch ($attr['key'])
            {
                case 'bWludGVy': // minter
                    $result['from'] = base64_decode($attr['value']);
                    break;
                case 'YW1vdW50': // amount
                    $result['amount'] = base64_decode($attr['value']);
                    break;
            }
        }

        return $result;
    }

    // Returns null|array('from' => addr, 'amount' => string_array)
    function parse_burn_event(?array $attributes): ?array
    {
        if (is_null($attributes))
            throw new ModuleException('Invalid `attributes` for burn parsing (is null)!');

        $return = ['from' => null, 'amount' => null, 'extra' => null];
        foreach ($attributes as $attr)
        {
            switch ($attr['key'])
            {
                case 'YnVybmVy': // burner
                    $return['from'] = base64_decode($attr['value']);
                    break;
                case 'YW1vdW50': // amount
                    $return['amount'] = base64_decode($attr['value']);
                    break;
            }
        }

        return $return;
    }

    // Returns null|array('from' => addr, 'to' => addr, 'amount' => string_array)
    function parse_transfer_event(?array $attributes): ?array
    {
        if (is_null($attributes))
            throw new ModuleException('Invalid `attributes` for coin_received parsing (is null)!');

        $result = ['from' => null, 'to' => null, 'amount' => null];
        foreach ($attributes as $attr)
        {
            if (is_null($attr['value']))
                return null;

            switch ($attr['key'])
            {
                case 'c2VuZGVy': // sender
                    $result['from'] = base64_decode($attr['value']);
                    break;
                case 'cmVjaXBpZW50': // recipient
                    $result['to'] = base64_decode($attr['value']);
                    break;
                case 'YW1vdW50': // amount
                    $result['amount'] = explode(',', base64_decode($attr['value']));
                    break;
            }
        }

        return $result;
    }

    // Detects swap operation in early blocks to set up special addresses.
    function detect_swap_events(?array $tx_events): bool
    {
        foreach ($tx_events as $tx_event)
        {
            switch ($tx_event['type'])
            {
                case 'swap_transacted':
                case 'deposit_to_pool':
                case 'withdraw_from_pool':
                    return true;
            }
        }

        return false;
    }

    // Parse the knowning denoms from $this->cosmos_known_denoms_exp
    // denom_amount format: {amount}{denom} (ex. 1234uatom)
    function denom_amount_to_amount(?string $denom_amount): ?string
    {
        if (str_contains($denom_amount, ','))
            throw new ModuleException("Expected single denom amount, array detected.");

        $parts = preg_split('/(?<=[0-9])(?=[a-z]+)/i', $denom_amount, limit: 2);
        if (!is_numeric($parts[0]))
            throw new ModuleException("Invalid denom amount format (not numeric): {$denom_amount}");

        $exp_ind = array_search($parts[1], $this->cosmos_known_denoms);
        if ($exp_ind === false)
            return null;

        $exp = $this->cosmos_known_denoms_exp[$exp_ind];
        return $parts[0] . str_repeat('0', $exp);
    }

    // Parse {amount}ibc/{denom} to null|array('amount' => string, 'currency' => string)
    function denom_amount_to_ibc_amount(?string $denom_amount): ?array
    {
        if (str_contains($denom_amount, ','))
            throw new ModuleException("Expected single denom amount, array detected.");

        $parts = preg_split('/(?<=[0-9])(?=[a-z]+)/i', $denom_amount, limit: 2);
        if (!is_numeric($parts[0]))
            throw new ModuleException("Invalid denom amount format (not numeric): {$denom_amount}");

        if (!str_contains($parts[1], 'ibc/'))
            return null;

        // Replace ibc/ to ibc_
        return ['amount' => $parts[0], 'currency' => str_replace('/', '_', $parts[1])];
    }
}
