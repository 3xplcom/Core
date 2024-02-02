<?php declare(strict_types=1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  Common Cosmos SDK functions and enums  */

enum CosmosSpecialFeatures
{
    // If the tx_results array has double tx events at the end.
    case HasDoublesTxEvents;
    // If the tx_results not base64 encoded.
    case HasDecodedValues;
    // There is no code field in tx_results
    case HasNotCodeField;
    // There is no coin_received event for fee_collector
    case HasNotFeeCollectorRecvEvent;
}

enum CosmosSpecialTransactions: string
{
    case Mint = 'm';
    case Burn = 'b';
}

trait CosmosTraits
{
    public function inquire_latest_block()
    {
        $response = requester_single($this->select_node(), endpoint: 'status', timeout: $this->timeout);
        $response = $response['result'] ?? $response;
        return (int) $response['sync_info']['latest_block_height'];
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
            $result = requester_multi_process($curl_results[0]);
            $result = $result['result'] ?? $result;
            $this->block_hash = $result['block_id']['hash'];
            $this->block_time = date('Y-m-d H:i:s', strtotime($result['block']['header']['time']));
            foreach ($curl_results as $curl_result)
            {
                $result1 = requester_multi_process($curl_result);
                $result1 = $result1['result'] ?? $result1;
                if ($result1['block_id']['hash'] !== $this->block_hash)
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

        foreach ($tx_events as $tx_event)
        {
            if ($tx_event['type'] === 'use_feegrant')
            {
                foreach ($tx_event['attributes'] as $attr)
                {
                    switch ($attr['key'])
                    {
                        case 'granter':
                        case 'Z3JhbnRlcg==': // granter
                            $fee_info['fee_payer'] = $this->try_base64_decode($attr['value']);
                            break;
                    }
                }
            }

            if ($tx_event['type'] === 'tx')
            {
                foreach ($tx_event['attributes'] as $attr)
                {
                    switch ($attr['key'])
                    {
                        case 'fee':
                        case 'ZmVl': // fee
                            if (is_null($attr['value'])) // zero fee for tx
                                $fee_info['fee'] = '0';
                            else
                                $fee_info['fee'] = $this->denom_amount_to_amount($this->try_base64_decode($attr['value']));
                            break;

                        case 'fee_payer':
                        case 'ZmVlX3BheWVy': // fee_payer
                            if (is_null($fee_info['fee_payer']))
                                $fee_info['fee_payer'] = $this->try_base64_decode($attr['value']);
                            break;

                        case 'acc_seq':
                        case 'YWNjX3NlcQ==': // acc_seq in format {addr}/{num}
                            if (is_null($fee_info['fee_payer']))
                                $fee_info['fee_payer'] = explode('/', $this->try_base64_decode($attr['value']))[0];
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

        foreach ($tx_events as $tx_event)
        {
            if ($tx_event['type'] === 'use_feegrant')
            {
                foreach ($tx_event['attributes'] as $attr)
                {
                    switch ($attr['key'])
                    {
                        case 'granter':
                        case 'Z3JhbnRlcg==': // granter
                            $fee_info['fee_payer'] = $this->try_base64_decode($attr['value']);
                            break;
                    }
                }
            }

            if ($tx_event['type'] === 'tx')
            {
                foreach ($tx_event['attributes'] as $attr)
                {
                    switch ($attr['key']) {
                        case 'fee':
                        case 'ZmVl': // fee
                            if (!is_null($attr['value']))
                            {
                                $ibc_amount = $this->denom_amount_to_ibc_amount($this->try_base64_decode($attr['value']));
                                if (!is_null($ibc_amount))
                                {
                                    $fee_info['fee'] = $ibc_amount['amount'];
                                    $fee_info['fee_currency'] = $ibc_amount['currency'];
                                }
                            }
                            break;

                        case 'fee_payer':
                        case 'ZmVlX3BheWVy': // fee_payer
                            if (is_null($fee_info['fee_payer']))
                                $fee_info['fee_payer'] = $this->try_base64_decode($attr['value']);
                            break;

                        case 'acc_seq':
                        case 'YWNjX3NlcQ==': // acc_seq in format {addr}/{num}
                            if (is_null($fee_info['fee_payer']))
                                $fee_info['fee_payer'] = explode('/', $this->try_base64_decode($attr['value']))[0];
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
                case 'spender':
                case 'c3BlbmRlcg==': // spender
                    $result['from'] = $this->try_base64_decode($attr['value']);
                    break;

                case 'amount':
                case 'YW1vdW50': // amount
                    $result['amount'] = explode(',', $this->try_base64_decode($attr['value']));
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
                case 'receiver':
                case 'cmVjZWl2ZXI=': // receiver
                    $result['to'] = $this->try_base64_decode($attr['value']);
                    break;

                case 'amount':
                case 'YW1vdW50': // amount
                    $result['amount'] = explode(',', $this->try_base64_decode($attr['value']));
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
                case 'minter':
                case 'bWludGVy': // minter
                    $result['from'] = $this->try_base64_decode($attr['value']);
                    break;

                case 'amount':
                case 'YW1vdW50': // amount
                    $result['amount'] = $this->try_base64_decode($attr['value']);
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
                case 'burner':
                case 'YnVybmVy': // burner
                    $return['from'] = $this->try_base64_decode($attr['value']);
                    break;

                case 'amount':
                case 'YW1vdW50': // amount
                    $return['amount'] = $this->try_base64_decode($attr['value']);
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
                case 'sender':
                case 'c2VuZGVy': // sender
                    $result['from'] = $this->try_base64_decode($attr['value']);
                    break;

                case 'recipient':
                case 'cmVjaXBpZW50': // recipient
                    $result['to'] = $this->try_base64_decode($attr['value']);
                    break;

                case 'amount':
                case 'YW1vdW50': // amount
                    $result['amount'] = explode(',', $this->try_base64_decode($attr['value']));
                    break;
            }
        }

        return $result;
    }

    // Returns null|array('from' => addr, 'to' => addr, 'amount' => string, 'currency' => addr, 'extra' => string)
    function parse_wasm_cw20_event(?array $attributes): ?array
    {
        if (is_null($attributes))
            throw new ModuleException('Invalid `attributes` for wasm parsing (is null)!');

        $result = ['from' => null, 'to' => null, 'amount' => null, 'currency' => null, 'extra' => null];
        $action = null;
        foreach ($attributes as $attr)
        {
            switch ($attr['key'])
            {
                case '_contract_address':
                case 'X2NvbnRyYWN0X2FkZHJlc3M=': // _contract_address
                    $result['currency'] = $this->try_base64_decode($attr['value']);
                    break;

                case 'action':
                case 'YWN0aW9u': // action
                    $action = $this->try_base64_decode($attr['value']);
                    switch ($action)
                    {
                        case 'transfer':
                        case 'send':
                        case 'transfer_from':
                            break;

                        case 'mint':
                            $result['extra'] = CosmosSpecialTransactions::Mint->value;
                            $result['from'] = 'the-void';
                            break;

                        case 'burn':
                        case 'burn_from':
                            $result['extra'] = CosmosSpecialTransactions::Burn->value;
                            $result['to'] = 'the-void';
                            break;

                        default:
                            return null; // Skips not cw20 actions
                    }

                    break;

                case 'from':
                case 'ZnJvbQ==': // from
                    $result['from'] = $this->try_base64_decode($attr['value']);
                    break;

                case 'to':
                case 'dG8=': // to
                    $result['to'] = $this->try_base64_decode($attr['value']);
                    break;

                case 'amount':
                case 'YW1vdW50': // amount
                    $result['amount'] = $this->try_base64_decode($attr['value']);
                    break;
            }
        }

        // 'action' is necessary field
        if (is_null($action))
            return null;

        // Check for cw721 mint/burn events and skip
        if ($result['from'] === 'the-void' && is_null($result['amount']))
            return null;
        if ($result['to'] === 'the-void' && is_null($result['amount']))
            return null;

        // Additional checks for not cw20 events
        if (is_null($result['from']) || is_null($result['to']) || is_null($result['currency']))
            return null;

        return $result;
    }

    // Returns null|array('from' => addr, 'to' => addr, 'currency' => addr, 'extra' => token_id_string)
    function parse_wasm_cw721_event(?array $attributes): ?array
    {
        if (is_null($attributes))
            throw new ModuleException('Invalid `attributes` for wasm parsing (is null)!');

        $result = ['from' => null, 'to' => null, 'currency' => null, 'extra' => null];
        $action = null;
        $minter = null;
        $owner = null;
        $amount_presents = false;
        foreach ($attributes as $attr)
        {
            switch ($attr['key'])
            {
                case '_contract_address':
                case 'X2NvbnRyYWN0X2FkZHJlc3M=': // _contract_address
                    $result['currency'] = $this->try_base64_decode($attr['value']);
                    break;

                case 'action':
                case 'YWN0aW9u': // action
                    $action = $this->try_base64_decode($attr['value']);
                    switch ($action)
                    {
                        case 'transfer_nft':
                        case 'send_nft':
                            break;

                        case 'mint':
                            $result['from'] = 'the-void';
                            break;

                        case 'burn':
                            $result['to'] = 'the-void';
                            break;

                        default:
                            return null; // Skips not cw721 actions
                    }

                    break;

                case 'minter':
                case 'bWludGVy': // minter
                    $minter = $this->try_base64_decode($attr['value']);
                    break;

                case 'owner':
                case 'b3duZXI=': // owner
                    $owner = $this->try_base64_decode($attr['value']);
                    break;

                case 'sender':
                case 'c2VuZGVy': // sender
                    $result['from'] = $this->try_base64_decode($attr['value']);
                    break;

                case 'recipient':
                case 'cmVjaXBpZW50': // recipient
                    $result['to'] = $this->try_base64_decode($attr['value']);
                    break;

                case 'token_id':
                case 'dG9rZW5faWQ=': // token_id
                    $result['extra'] = $this->try_base64_decode($attr['value']);
                    break;

                case 'amount':
                case 'YW1vdW50': // amount
                    $amount_presents = true;
                    break;
            }
        }

        // 'action' is necessary field
        if (is_null($action))
            return null;

        // Check for cw20 mint/burn events and skip
        if ($result['from'] === 'the-void' && $amount_presents)
            return null;
        if ($result['to'] === 'the-void' && $amount_presents)
            return null;

        // Clarifies 'to' for mint event
        if ($result['from'] === 'the-void')
            $result['to'] = is_null($owner) ? $minter : $owner;

        // Additional checks for not documented cw721 event
        if (is_null($result['from']) || is_null($result['to']) || is_null($result['currency']) || is_null($result['extra']))
            return null;

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

    // Parse the knowning denoms from $this->cosmos_known_denoms
    // denom_amount format: {amount}{denom} (ex. 1234uatom)
    function denom_amount_to_amount(?string $denom_amount): ?string
    {
        if ($denom_amount === '')
            return '0';

        if (str_contains($denom_amount, ','))
            throw new ModuleException("Expected single denom amount, array detected.");

        $parts = preg_split('/(?<=[0-9])(?=[a-z]+)/i', $denom_amount, limit: 2);
        if (!is_numeric($parts[0]))
            throw new ModuleException("Invalid denom amount format (not numeric): {$denom_amount}");

        if (!array_key_exists($parts[1], $this->cosmos_known_denoms))
            return null;

        return $parts[0] . str_repeat('0', $this->cosmos_known_denoms[$parts[1]]);
    }

    // Parse {amount}ibc/{denom} to null|array('amount' => string, 'currency' => string)
    function denom_amount_to_ibc_amount(?string $denom_amount): ?array
    {
        if ($denom_amount === '')
            return null;

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

    // Parse {amount}factory/{creator}/{denom} to null|array('amount' => string, 'currency' => string, 'name' => string)
    function denom_amount_to_token_factory_amount(?string $denom_amount): ?array
    {
        if ($denom_amount === '')
            return null;

        if (str_contains($denom_amount, ','))
            throw new ModuleException("Expected single denom amount, array detected.");

        $parts = preg_split('/(?<=[0-9])(?=[a-z]+)/i', $denom_amount, limit: 2);

        if (!is_numeric($parts[0]))
            throw new ModuleException("Invalid denom amount format (not numeric): {$denom_amount}");

        if (!str_contains($parts[1], 'factory/'))
            return null;

        return ['amount' => $parts[0], 'currency' => str_replace('/', '_', $parts[1])];
    }

    // In some chains may be doubled events for fee paying.
    function erase_double_fee_events(array &$events) {
        if (empty($events))
            return;

        // Check 'tx' events in the end of the list exists
        if ($events[count($events) - 1]['type'] !== 'tx')
            return;

        $erase_index = null;
        for ($i = count($events) - 1; $i >= 0; $i--)
        {
            if ($events[$i]['type'] === 'coin_spent')
            {
                $erase_index = count($events) - $i;
                break;
            }
        }

        // Erase the exact events from end of list
        $events = array_slice($events, 0, -$erase_index);
    }

    function try_base64_decode($data): string
    {
        return in_array(CosmosSpecialFeatures::HasDecodedValues, $this->extra_features) ? $data : base64_decode($data);
    }
}
