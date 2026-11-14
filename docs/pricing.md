# Pricing

`App\Pricing\PriceCalculator` is the single source of truth for what a
customer pays:

1. Guest price (the product price, or the variant price if it has one).
2. **Member discount:** active members get `pricing.member_discount_bps` off
   each unit (default 15%), rounded half-up.
3. **Coupon** (percent or fixed), applied to goods after the member
   discount. Options: min subtotal, max discount, dates, total uses,
   uses per customer, members-only.
4. **Shipping** (calculated in PR 7).
5. **Free shipping for members** when goods after discounts are
   ≥ `shipping.member_free_over` (default 3,000 ETB).

The quote also gives `member_savings_preview`, which is what a guest would
save as a member. It powers the "Join & save" prompt at the cart.

Every product in the API has `price`, `member_price` and `member_saving`.

## Profit protection
`ProfitCheck::worstCase($product)` works out the worst case for one unit
sold to a member: member price − commission on every enabled product level
− payment fee (`payments.fee_bps`) − cost. Admin product responses include
`profit` and these warnings:
- `price_below_cost_ratio`: price < 2.5× cost
- `unprofitable_member_order`: the worst case above is negative
