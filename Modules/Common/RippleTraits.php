<?php declare(strict_types = 1);

/*  Copyright (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com
 *  Distributed under the MIT software license, see the accompanying file LICENSE.md  */

/*  This module process main Ripple transfers. Requires a Ripple node.  */

enum RippleSpecialTransactions: string
{
    // https://github.com/XRPLF/rippled/blob/649c11a78e0d02fc370573cefc65458586037848/src/ripple/protocol/TxFormats.h#L57
    case Payment = 'p';     // 83305991

    /** This transaction type creates an escrow object. */
    case EscrowCreate = 'ec';       // 82979141

    /** This transaction type completes an existing escrow. */
    case EscrowFinish = 'ef';       // 82989564

    /** This transaction type adjusts various account settings. */
    case AccountSet = 'as';         // 82979591

    /** This transaction type cancels an existing escrow. */
    case EscrowCancel = 'ea';       // 82991256

    /** This transaction type sets or clears an account's "regular key". */
    case SetRegularKey = 'srk';     // 82978869

    /** This transaction type is deprecated; it is retained for historical purposes. */
    case NickNameSet = 'nns'; // never

    /** This transaction type creates an offer to trade one asset for another. */
    case OfferCreate = 'oc';    // 83305991

    /** This transaction type cancels existing offers to trade one asset for another. */
    case OfferCancel = 'oa';    // 83305993

    /** This transaction type is deprecated; it is retained for historical purposes. */
    case Contract = 'c';       // never

    /** This transaction type creates a new set of tickets. */
    case TicketCreate = 'tc';       // 83305992

    /** This identifier was never used, but the slot is reserved for historical purposes. */
    case SpinalTap = 'st';      // never

    /** This transaction type modifies the signer list associated with an account. */
    case SignerListSet = 'sls';     // 82984705

    /** This transaction type creates a new unidirectional XRP payment channel. */
    case PaymentChannelCreate = 'pcc';  // 83307994

    /** This transaction type funds an existing unidirectional XRP payment channel. */
    case PaymentChannelFund = 'pcf';    // 82979740

    /** This transaction type submits a claim against an existing unidirectional payment channel. */
    case PaymentChannelClaim = 'pca';   // 83307240

    /** This transaction type creates a new check. */
    case CheckCreate = 'cc';            // 83086706

    /** This transaction type cashes an existing check. */
    case CheckCash = 'ca';              // 83106440

    /** This transaction type cancels an existing check. */
    case CheckCancel = 'cn';

    /** This transaction type grants or revokes authorization to transfer funds. */
    case DepositPreauth = 'dp';

    /** This transaction type modifies a trustline between two accounts. */
    case TrustSet = 'tt';   // 83305991

    /** This transaction type deletes an existing account. */
    case AccountDelete = 'ad';      // 82977534

    /** This transaction type installs a hook. */
    case HookSet = 'hs';        // never 

    /** This transaction mints a new NFT. */
    case NFTokenMint = 'ntm';   // 83305996

    /** This transaction burns (i.e. destroys) an existing NFT. */
    case NFTokenBurn = 'ntb';   // 82977917

    /** This transaction creates a new offer to buy or sell an NFT. */
    case NFTokenCreateOffer = 'ntc';    // 83305998

    /** This transaction cancels an existing offer to buy or sell an existing NFT. */
    case NFTokenCancelOffer = 'ntn';    // 83305993

    /** This transaction accepts an existing offer to buy or sell an existing  NFT. */
    case NFTokenAcceptOffer = 'nta';    // 83305991

    // These transactions are not in prod now, will wait for them
    /** This transaction claws back issued tokens. */
    case Clawback = 'cb';

    /** This transaction type creates an AMM instance */
    case AMMCreate = 'ac';

    /** This transaction type deposits into an AMM instance */
    case AMMDeposit = 'aa';

    /** This transaction type withdraws from an AMM instance */
    case AMMWithdraw = 'aw';

    /** This transaction type votes for the trading fee */
    case AMMVote = 'av';

    /** This transaction type bids for the auction slot */
    case AMMBid = 'ab';

    /** This transaction type deletes AMM in the empty state */
    case AMMDelete = 'ae';

    case UNLModify = 'um';

    case EnableAmendment = 'em';

    // /** This transactions creates a crosschain sequence number */
    // ttXCHAIN_CREATE_CLAIM_ID = 41,

    // /** This transactions initiates a crosschain transaction */
    // ttXCHAIN_COMMIT = 42,

    // /** This transaction completes a crosschain transaction */
    // ttXCHAIN_CLAIM = 43,

    // /** This transaction initiates a crosschain account create transaction */
    // ttXCHAIN_ACCOUNT_CREATE_COMMIT = 44,

    // /** This transaction adds an attestation to a claimid*/
    // ttXCHAIN_ADD_CLAIM_ATTESTATION = 45,

    // /** This transaction adds an attestation to a claimid*/
    // ttXCHAIN_ADD_ACCOUNT_CREATE_ATTESTATION = 46,

    // /** This transaction modifies a sidechain */
    // ttXCHAIN_MODIFY_BRIDGE = 47,

    // /** This transactions creates a sidechain */
    // ttXCHAIN_CREATE_BRIDGE = 48,


    //This system-generated transaction type is used to update the status of the various amendments.
    // For details, see: https://xrpl.org/amendments.html

    // ttAMENDMENT = 100,

    // This system-generated transaction type is used to update the network's fee settings.    
    // For details, see: https://xrpl.org/fee-voting.html
    // ttFEE = 101,

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

trait RippleTraits
{
    //  main idea, we have in the end e^x and our decimals 10^96
    //  so we do first: 96 + x = y
    //  second: count amount after '.'
    //  if amount > y => error
    private function to_96($number)
    {
        $decimals = 96;
        $e = strpos($number, 'e');
        $e_num = 0;
        if ($e) 
        {
            $e_num = substr($number, $e + 1);
            $number = substr($number, 0, $e);
        }
        // 1.0      - len = 3, pos = 1, nap = 1
        // 1.000    - len = 5, pos = 1, nap = 3
        // 100.01   - len = 6, pos = 3, nap = 2
        // 10.0101  - len = 7, pos = 2, nap = 4
        $point = strpos($number, '.');
        $nums_after_point = $point ? strlen($number) - 1 - $point : 0;
        $y = $decimals + $e_num;
        if ($y < $nums_after_point) 
        {
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
}
