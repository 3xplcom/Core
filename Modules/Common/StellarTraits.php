<?php declare(strict_types = 1);

/*  Idea (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023-2024 3xpl developers, 3@3xpl.com, see CONTRIBUTORS.md
 *  Distributed under the MIT software license, see LICENSE.md  */

/*  Common Stellar functions and enums  */

enum StellarSpecialTransactions: string
{
    // /Stellar/stellar-core-master/src/transactions/OperationFrame.cpp
    case CREATE_ACCOUNT = 'p';
    case PAYMENT = 'ec';
    case PATH_PAYMENT_STRICT_RECEIVE = 'ef';
    case MANAGE_SELL_OFFER = 'as';
    case CREATE_PASSIVE_SELL_OFFER = 'ea';
    case SET_OPTIONS = 'srk';
    case CHANGE_TRUST = 'nns';
    case ALLOW_TRUST = 'oc';
    case ACCOUNT_MERGE = 'oa';
    case INFLATION = 'c';
    case MANAGE_DATA = 'tc';
    case BUMP_SEQUENCE = 'st';
    case MANAGE_BUY_OFFER = 'sls';
    case PATH_PAYMENT_STRICT_SEND = 'pcc';
    case CREATE_CLAIMABLE_BALANCE = 'pcf';
    case CLAIM_CLAIMABLE_BALANCE = 'pca';   // /ops/210142562131509348
    case BEGIN_SPONSORING_FUTURE_RESERVES = 'cc';
    case END_SPONSORING_FUTURE_RESERVES = 'ca';
    case REVOKE_SPONSORSHIP = 'cn';
    case CLAWBACK = 'dp';
    case CLAWBACK_CLAIMABLE_BALANCE = 'tt';
    case SET_TRUST_LINE_FLAGS = 'ad';
    case LIQUIDITY_POOL_DEPOSIT = 'hs';
    case LIQUIDITY_POOL_WITHDRAW = 'ntm';
    case INVOKE_HOST_FUNCTION = 'ntb';
    case EXTEND_FOOTPRINT_TTL = 'ntc';
    case RESTORE_FOOTPRINT = 'ntn';

    case FEE = 'f';
    case SOURCE_ACCOUNT = 'o';

    public static function fromName(string $name): string
    {
        foreach (self::cases() as $status) {
            if ($name === strtolower($status->name)) {
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

trait StellarTraits
{
    final public function inquire_latest_block()
    {
        return (int)requester_single($this->select_node(),
        timeout: $this->timeout)['history_latest_ledger'];
    }

    // Old and slow way to get our transactions
    private function get_data_with_cursor(string $path, int $count) {
        $cursor = 'now';
        $data_array = [];
        for($i = $count; $i > 0;)
        {
            $limit = 200;
            if ($limit < $i)
            {
                $i -= $limit;
            } else {
                $limit = $i;
                $i = 0;
            }
            // "ledgers/{$block_id}/transactions?order=desc&limit=%s&include_failed=true&cursor=%s", 
            $path_formed = sprintf($path, $limit, $cursor);
            $out_array = requester_single(
                $path_formed,
                result_in: '_embedded',
                timeout: $this->timeout
            )['records'];
            $data_array = array_merge($data_array, $out_array);
            $cursor = $data_array[count($data_array) - 1]['paging_token'];
        }
        return $data_array;
    }

    private function get_effects(string $path)
    {
        $data_array = [];
        $cursor = 'now';
        while (true) {
            $path_formed = sprintf($path, $cursor);
            $data = requester_single(
                $path_formed,
                result_in: '_embedded',
                timeout: $this->timeout
            )['records'];
            if (count($data) > 0) {
                $data_array = array_merge($data_array, $data);
                $cursor = $data_array[count($data_array) - 1]['paging_token'];
            } else {
                return $data_array;
            }
        }
    }

    // main idea, we have in the end e^x and our decimals 10^7
    // so we do first: 7 + x = y
    // second: count amount after '.'
    // if amount > y => error
    private function to_7($number)
    {
        $decimals = 7;
        $e_num = 0;
        if ((float)$number == 0.0)
        {
            return "0";
        }

        // 1.0 - len = 3, pos = 1, nap = 1
        // 1.000 - len = 5, pos = 1, nap = 3
        // 100.01 - len = 6, pos = 3, nap = 2
        // 10.0101 - len = 7, pos = 2, nap = 4
        $point = strpos($number, '.');
        $nums_after_point = $point ? strlen($number) - 1 - $point : 0;
        $y = $decimals + $e_num;
        if ($y < $nums_after_point) {
            throw new ModuleError("Too many decimals in {$number}");
        } else {

            $zeros_in_the_end = $y - $nums_after_point;
            if ($point)
            $num = substr($number, 0, $point) . substr($number, $point + 1);
            else
            $num = $number;

            $pad = str_pad('', $zeros_in_the_end, '0');
            $num .= $pad;
            $num = ltrim($num, '0');
            return $num;
        }
    }

    public function ensure_block($block_id, $break_on_first = false)
    {
        $multi_curl = [];

        foreach ($this->nodes as $node)
        {
            $multi_curl[] = requester_multi_prepare($node . "ledgers/{$block_id}", timeout: $this->timeout);

            if ($break_on_first)
                break;
        }

        try
        {
            $curl_results = requester_multi($multi_curl, limit: count($this->nodes), timeout: $this->timeout);
        }
        catch (RequesterException $e)
        {
            throw new RequesterException("ensure_ledger(ledger_index: {$block_id}): no connection, previously: " . $e->getMessage());
        }

        $results = requester_multi_process($curl_results[0]);
        $hash = $results['hash'];
        $this->block_time = date('Y-m-d H:i:s', strtotime($results['closed_at']));
        $this->transaction_count = $results['successful_transaction_count'] + $results['failed_transaction_count'];
        $this->operation_count = $results['operation_count'];
        $this->paging_token = $results['paging_token'];

        if (count($curl_results) > 1) 
        {
            foreach ($curl_results as $result) 
            {
                if (requester_multi_process($result)['hash'] !== $hash) 
                {
                    throw new ConsensusException("ensure_ledger(ledger_index: {$block_id}): no consensus");
                }
            }
        }

        $this->block_hash = $hash;
    }
}
