<?php
/**
 * Plain-language progress for an order: the steps, where the order is now, and one sentence that says
 * what is happening and what the user can do next. Used on the Orders page.
 *
 * @param array      $o        order row (with payment_status, accepted_at, auto_release_at ...)
 * @param string     $role     'buyer' or 'seller'
 * @param array|null $dispute  the order's dispute row, if any
 */
function orderProgress(array $o, string $role, ?array $dispute): array
{
    $isBuy   = ($o['type'] === 'buy');
    $status  = $o['status'];
    $pay     = $o['payment_status'] ?? 'unpaid';
    $d       = fn($t) => $t ? date('j M H:i', strtotime($t)) : '';
    $steps   = $isBuy ? ['Requested', 'Accepted', 'Paid (held)', 'Completed'] : ['Requested', 'Accepted', 'Completed'];
    $terminal = null;
    $current  = 0;

    if ($status === 'declined') {
        $terminal = 'Declined';
    } elseif ($pay === 'refunded') {
        $terminal = 'Refunded';
    } elseif ($status === 'cancelled') {
        $terminal = 'Cancelled';
    } elseif ($status === 'completed') {
        $current = count($steps) - 1;
    } elseif ($status === 'accepted') {
        $current = ($isBuy && $pay === 'held') ? 2 : 1;
    }

    $open    = $dispute && $dispute['status'] === 'open';
    $replied = $dispute && !empty($dispute['seller_replied_at']);
    $msg = '';
    $buyer = ($role === 'buyer');

    if ($status === 'pending') {
        $msg = $buyer ? 'Waiting for the seller to accept. You have not paid anything yet.'
                      : 'Accept or decline this request. Nothing is paid until you accept.';
    } elseif ($status === 'declined') {
        $msg = $buyer ? 'The seller declined this request. Nothing was charged.' : 'You declined this request.';
    } elseif ($pay === 'refunded') {
        $msg = $buyer ? 'Your payment was refunded and the order is cancelled.' : 'The payment was refunded to the buyer and the order is cancelled.';
    } elseif ($status === 'cancelled') {
        $msg = 'This order was cancelled. No money was moved.';
    } elseif ($status === 'completed') {
        $msg = $isBuy ? ($buyer ? 'Completed. The payment was released to the seller.' : 'Completed. The payment was released to you.')
                      : 'Completed.';
    } elseif ($open && $pay === 'held') {
        if ($buyer) {
            $msg = 'The payment is frozen while a problem report is open. '
                 . (!empty($dispute['seller_deadline']) && !$replied
                     ? 'The seller must reply by ' . $d($dispute['seller_deadline']) . ', otherwise you are refunded automatically. '
                     : '')
                 . 'An administrator decides.';
        } else {
            $msg = 'The payment is frozen while a problem report is open. '
                 . (!empty($dispute['seller_deadline']) && !$replied
                     ? 'Reply by ' . $d($dispute['seller_deadline']) . ', otherwise the buyer is refunded automatically. '
                     : '')
                 . 'An administrator decides.';
        }
    } elseif ($isBuy && $pay === 'held') {
        $auto = $d($o['auto_release_at'] ?? null);
        $msg = $buyer
            ? 'Your payment is safe in escrow: the seller is not paid yet. Confirm receipt when you have the item.'
              . ($auto ? ' If you do nothing, it is released to the seller automatically on ' . $auto . '.' : '')
              . ' If something is wrong, report a problem and the payment is frozen.'
            : 'The buyer has paid and the money is held by TrustTrade. Hand over the item. It is released to you when the buyer confirms receipt'
              . ($auto ? ', or automatically on ' . $auto . ' if no problem is reported.' : '.');
    } elseif ($isBuy && $status === 'accepted') {
        $cancel = !empty($o['accepted_at']) ? $d(date('Y-m-d H:i:s', strtotime($o['accepted_at'] . ' +' . Automation::CANCEL_DAYS . ' days'))) : '';
        $msg = $buyer
            ? 'Accepted. Pay to secure the item' . ($cancel ? '; unpaid requests are cancelled automatically on ' . $cancel : '') . '. Your money stays in escrow until you confirm receipt.'
            : 'Accepted. Waiting for the buyer to pay' . ($cancel ? '; the request is cancelled automatically on ' . $cancel . ' if unpaid' : '') . '.';
    } elseif ($status === 'accepted') {
        $msg = 'Accepted. Arrange the swap through Messages, then mark it completed.';
    }

    return ['steps' => $steps, 'current' => $current, 'terminal' => $terminal, 'message' => $msg];
}