<?php
/**
 * The delivery step of an order: how the item gets from the seller to the buyer.
 * One suggestion at a time: one person suggests a way, the other person accepts it or suggests something else.
 * Shown on the Orders page and inside the chat.
 */

function deliveryMethods(): array
{
    return [
        'meetup' => 'Meet-up on campus',
        'pickup' => 'Pick-up from the seller',
        'post'   => 'Sent by post or parcel',
    ];
}

function deliveryH(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/** One plain sentence, for example "Meet-up on campus at Library entrance, Sat 8 Nov 14:00". */
function deliverySummary(array $o): string
{
    $methods = deliveryMethods();
    $m = $methods[$o['delivery_method'] ?? ''] ?? '';
    if ($m === '') {
        return '';
    }
    $text = $m . ' at ' . ($o['delivery_place'] ?? '');
    if (!empty($o['delivery_time'])) {
        $text .= ($o['delivery_method'] === 'post' ? ', sent by ' : ', ') . date('D j M H:i', strtotime($o['delivery_time']));
    }
    return $text;
}

/** The suggestion form. $o may hold an earlier suggestion that is used to pre-fill the fields. */
function deliveryForm(array $o, string $back, string $buttonLabel): string
{
    $sel  = $o['delivery_method'] ?? 'meetup';
    $time = !empty($o['delivery_time']) ? date('Y-m-d\TH:i', strtotime($o['delivery_time'])) : '';
    $min  = date('Y-m-d\TH:i');
    $h = '<form method="POST" action="orders.php" class="form-stack delivery-form">'
       . '<input type="hidden" name="order_id" value="' . (int) $o['id'] . '">'
       . '<input type="hidden" name="action" value="delivery_propose">'
       . '<input type="hidden" name="back" value="' . deliveryH($back) . '">'
       . '<label>How will the item be handed over?<select name="method">';
    foreach (deliveryMethods() as $key => $label) {
        $h .= '<option value="' . $key . '"' . ($key === $sel ? ' selected' : '') . '>' . deliveryH($label) . '</option>';
    }
    $h .= '</select></label>'
        . '<label>Where (a public place on campus, a pick-up area, or the parcel address)'
        . '<input type="text" name="place" maxlength="160" required value="' . deliveryH((string) ($o['delivery_place'] ?? '')) . '" placeholder="e.g. Library entrance, main building"></label>'
        . '<label>When (for a parcel: the day the seller will send it)'
        . '<input type="datetime-local" name="time" min="' . $min . '" value="' . deliveryH($time) . '"></label>'
        . '<label>Note (optional)<input type="text" name="note" maxlength="255" value="' . deliveryH((string) ($o['delivery_note'] ?? '')) . '" placeholder="e.g. I will wear a blue jacket"></label>'
        . '<button type="submit" class="btn-small">' . deliveryH($buttonLabel) . '</button>'
        . '</form>';
    return $h;
}

function deliveryPostButton(array $o, string $action, string $label, string $back, string $class = 'btn-small', string $extra = ''): string
{
    return '<form method="POST" action="orders.php" class="inline-form">'
         . '<input type="hidden" name="order_id" value="' . (int) $o['id'] . '">'
         . '<input type="hidden" name="action" value="' . $action . '">'
         . '<input type="hidden" name="back" value="' . deliveryH($back) . '">'
         . $extra
         . '<button type="submit" class="' . $class . '">' . deliveryH($label) . '</button></form>';
}

/**
 * The whole delivery box for one order.
 * @param string $otherName first name of the other person
 * @param string $back      where to return after an action (a conversation link) or '' for the Orders page
 */
function deliveryBox(array $o, int $me, string $otherName, string $back = ''): string
{
    $status = $o['status'] ?? '';
    $ds     = $o['delivery_status'] ?? 'none';
    $isSeller = ((int) ($o['seller_id'] ?? 0) === $me);
    $by     = (int) ($o['delivery_by'] ?? 0);
    $byMe   = ($by === $me);
    $summary = deliverySummary($o);

    $h = '<div class="delivery-box"><div class="delivery-title">Delivery</div>';

    if ($status === 'pending') {
        return $h . '<p class="muted">You can agree how the item is handed over once the seller has accepted the request.</p></div>';
    }
    if (!in_array($status, ['accepted', 'completed'], true)) {
        return '';
    }
    if ($status === 'completed') {
        $h .= $summary !== '' ? '<p>' . deliveryH($summary) . '</p>' : '<p class="muted">No delivery plan was recorded.</p>';
        return $h . '</div>';
    }

    if ($ds === 'none' || $summary === '') {
        $h .= '<p class="muted">Not arranged yet. Suggest a meet-up, a pick-up or a parcel, and ' . deliveryH($otherName) . ' can accept it.</p>'
            . '<details class="delivery-open"><summary class="btn-small">Arrange delivery</summary>' . deliveryForm($o, $back, 'Send suggestion') . '</details>';
    } elseif ($ds === 'proposed' && $byMe) {
        $h .= '<p><strong>You suggested:</strong> ' . deliveryH($summary) . '</p>'
            . (!empty($o['delivery_note']) ? '<p class="muted">' . deliveryH($o['delivery_note']) . '</p>' : '')
            . '<p class="muted">Waiting for ' . deliveryH($otherName) . ' to accept.</p>'
            . '<details class="delivery-open"><summary class="btn-ghost btn-small">Change my suggestion</summary>' . deliveryForm($o, $back, 'Send new suggestion') . '</details>';
    } elseif ($ds === 'proposed') {
        $h .= '<p><strong>' . deliveryH($otherName) . ' suggested:</strong> ' . deliveryH($summary) . '</p>'
            . (!empty($o['delivery_note']) ? '<p class="muted">' . deliveryH($o['delivery_note']) . '</p>' : '')
            . '<div class="delivery-actions">' . deliveryPostButton($o, 'delivery_accept', 'Accept', $back) . '</div>'
            . '<details class="delivery-open"><summary class="btn-ghost btn-small">Suggest something else</summary>' . deliveryForm($o, $back, 'Send my suggestion') . '</details>';
    } elseif ($ds === 'agreed') {
        $h .= '<p><span class="pill pill-held">Agreed</span> ' . deliveryH($summary) . '</p>'
            . (!empty($o['delivery_note']) ? '<p class="muted">' . deliveryH($o['delivery_note']) . '</p>' : '');
        if ($o['delivery_method'] === 'post' && $isSeller) {
            $h .= '<div class="delivery-actions">' . deliveryPostButton(
                $o, 'delivery_shipped', 'Mark as sent', $back, 'btn-small',
                '<input type="text" name="tracking" maxlength="60" placeholder="Tracking number (optional)" class="tracking-input">'
            ) . '</div>';
        }
        $h .= '<details class="delivery-open"><summary class="btn-ghost btn-small">Change the plan</summary>' . deliveryForm($o, $back, 'Send new suggestion') . '</details>';
    } elseif ($ds === 'shipped') {
        $h .= '<p><span class="pill pill-held">Sent</span> ' . deliveryH($summary) . '</p>'
            . (!empty($o['tracking_ref']) ? '<p class="muted">Tracking number: ' . deliveryH($o['tracking_ref']) . '</p>' : '')
            . '<p class="muted">The buyer presses "I received the item" when the parcel arrives.</p>';
    }

    $h .= '<p class="delivery-tip">Safety: meet in a public place on campus, look at the item before you confirm, and never pay or send money outside TrustTrade.'
        . (($o['payment_status'] ?? '') === 'held' ? ' The buyer\'s payment is held until the item is received.' : '') . '</p></div>';
    return $h;
}