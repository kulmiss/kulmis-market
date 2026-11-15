import {
  Activity,
  BadgePercent,
  Banknote,
  BookOpen,
  Medal,
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
        { title: 'Payments', url: '/payments', icon: Receipt, ability: 'manage-orders' },
        { title: 'Products', url: '/products', icon: Boxes, ability: 'manage-products', feature: 'shop' },
        { title: 'Categories', url: '/categories', icon: FolderTree, ability: 'manage-products', feature: 'shop' },
        { title: 'Coupons', url: '/coupons', icon: BadgePercent, ability: 'manage-products', feature: 'shop.coupons' },
        { title: 'Shipping', url: '/shipping', icon: Truck, ability: 'manage-orders', feature: 'shop' },
      ],
    },
    {
      title: 'Members',
      items: [
        { title: 'Members', url: '/members', icon: Users, ability: 'manage-members' },
        { title: 'Referral tree', url: '/referrals', icon: Network, ability: 'manage-referrals', feature: 'referral' },
        { title: 'Commissions', url: '/commissions', icon: BookOpen, ability: 'manage-referrals', feature: 'referral' },
        { title: 'Badges', url: '/badges', icon: Medal, ability: 'manage-settings', feature: 'badges' },
      ],
    },
    {
      title: 'Money',
      items: [
        { title: 'Cash-outs', url: '/cash-outs', icon: Banknote, ability: 'approve-cashouts', feature: 'wallet' },
        { title: 'Ledger', url: '/ledger', icon: Wallet, ability: 'manage-ledger' },
        { title: 'Fraud queue', url: '/fraud', icon: ShieldAlert, ability: 'review-fraud' },
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
        { title: 'Settings', url: '/config', icon: SlidersHorizontal, ability: 'manage-settings' },
        { title: 'Activity log', url: '/activity', icon: ScrollText, ability: 'view-activity' },
        { title: 'My account', url: '/settings', icon: Palette },
        { title: 'Status', url: '/status', icon: Activity, ability: 'view-reports' },
      ],
    },
  ],
}
