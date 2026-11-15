import {
  Activity,
  Hammer,
  Gift,
  Share2,
  Trophy,
  LifeBuoy,
  Package,
  Timer,
  BadgePercent,
  Banknote,
  BookOpen,
  FlaskConical,
  Medal,
  PiggyBank,
  Bot,
  Boxes,
  FileText,
  FolderTree,
  LayoutDashboard,
  Network,
  Palette,
  Radio,
  Receipt,
  ScrollText,
  ShieldAlert,
  ShoppingBag,
  SlidersHorizontal,
  Star,
  ToggleRight,
  Truck,
  UserRound,
  Users,
  Wallet,
} from 'lucide-react'
import { type SidebarData } from '../types'

/**
 * `feature` hides the link when that feature switch is off;
 * `ability` hides it for staff roles without that permission.
 */
export const sidebarData: SidebarData = {
  user: { name: '', email: '', avatar: '' },
  teams: [],
  navGroups: [
    {
      title: 'General',
      items: [
        { title: 'Dashboard', url: '/', icon: LayoutDashboard, ability: 'view-reports' },
        { title: 'Feature switches', url: '/features', icon: ToggleRight, ability: 'manage-features' },
      ],
    },
    {
      title: 'Shop',
      items: [
        { title: 'Orders', url: '/orders', icon: ShoppingBag, ability: 'manage-orders', feature: 'shop' },
        { title: 'Payments', url: '/payments', icon: Receipt, ability: 'view-payments' },
        { title: 'Products', url: '/products', icon: Boxes, ability: 'manage-products', feature: 'shop' },
        { title: 'Categories', url: '/categories', icon: FolderTree, ability: 'manage-products', feature: 'shop' },
        { title: 'Coupons', url: '/coupons', icon: BadgePercent, ability: 'manage-products', feature: 'shop.coupons' },
        { title: 'Custom orders', url: '/custom-orders', icon: Hammer, ability: 'manage-orders', feature: 'shop.custom_orders' },
        { title: 'Gift cards', url: '/gift-cards', icon: Gift, ability: 'manage-orders', feature: 'shop.gift_cards' },
        { title: 'Flash sales', url: '/flash-sales', icon: Timer, ability: 'manage-products', feature: 'shop.flash_sales' },
        { title: 'Bundles', url: '/bundles', icon: Package, ability: 'manage-products', feature: 'shop.bundles' },
        { title: 'Reviews', url: '/reviews', icon: Star, ability: 'manage-products', feature: 'shop.reviews' },
        { title: 'Shipping', url: '/shipping', icon: Truck, ability: 'manage-orders', feature: 'shop' },
      ],
    },
    {
      title: 'Members',
      items: [
        { title: 'Members', url: '/members', icon: Users, ability: 'manage-members' },
        { title: 'Team tree', url: '/referrals', icon: Network, ability: 'manage-referrals', feature: 'referral' },
        { title: 'Team earnings', url: '/commissions', icon: BookOpen, ability: 'manage-referrals', feature: 'referral' },
        { title: 'Badges', url: '/badges', icon: Medal, ability: 'manage-settings', feature: 'badges' },
        { title: 'Badge bonus', url: '/badge-bonus', icon: PiggyBank, ability: 'manage-settings', feature: 'badges.bonus' },
        { title: 'Contests', url: '/contests', icon: Trophy, ability: 'manage-referrals', feature: 'referral' },
      ],
    },
    {
      title: 'Money',
      items: [
        { title: 'Cash-outs', url: '/cash-outs', icon: Banknote, ability: 'approve-cashouts', feature: 'wallet' },
        { title: 'Money history', url: '/ledger', icon: Wallet, ability: 'manage-ledger' },
        { title: 'Fraud checks', url: '/fraud', icon: ShieldAlert, ability: 'review-fraud' },
      ],
    },
    {
      title: 'Signals',
      items: [
        { title: 'Signals', url: '/signals', icon: Radio, ability: 'manage-signals', feature: 'signals' },
        { title: 'Subscribers', url: '/signals/subscribers', icon: UserRound, ability: 'manage-signals', feature: 'signals' },
        { title: 'Telegram bot', url: '/signals/telegram', icon: Bot, ability: 'manage-signals', feature: 'signals' },
      ],
    },
    {
      title: 'System',
      items: [
        { title: 'Pages & banners', url: '/content', icon: FileText, ability: 'manage-content' },
        { title: 'Share kit', url: '/share-kit', icon: Share2, ability: 'manage-content', feature: 'referral.share_kit' },
        { title: 'Help centre', url: '/help', icon: LifeBuoy, ability: 'answer-help', feature: 'site.help_centre' },
        { title: 'Settings', url: '/config', icon: SlidersHorizontal, ability: 'manage-settings' },
        { title: 'Demo data', url: '/demo', icon: FlaskConical, ability: 'manage-settings' },
        { title: 'Activity log', url: '/activity', icon: ScrollText, ability: 'view-activity' },
        { title: 'My account', url: '/settings', icon: Palette },
        { title: 'Status', url: '/status', icon: Activity, ability: 'view-reports' },
      ],
    },
  ],
}
