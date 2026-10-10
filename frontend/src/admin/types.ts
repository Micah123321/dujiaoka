export const sections = ['overview', 'stripe', 'paypal', 'notifications', 'quotes', 'runtime'] as const;
export type Section = typeof sections[number];
export type Scalar = string | number | null;
export type State = 'all' | 'pending' | 'failed' | 'completed' | 'reserved' | 'manual' | 'expired' | 'open' | 'closed';
export type ActionKey = 'retry' | 'retry-event' | 'retry-job' | 'reconcile' | 'publish' | 'expire';
export interface Navigation { key: string; label: string; href: string }
export interface WorkbenchConfig {
  section: Section;
  userName: string;
  csrf: string;
  endpoints: { data: string; action: string };
  navigation: Navigation[];
  management: Navigation[];
  canOperate: boolean;
}
export interface Card { key: string; label: string; value: Scalar; hint: string; tone: 'neutral' | 'good' | 'warn' }
export interface Column { key: string; label: string; format: 'text' | 'money' | 'status' | 'time' | 'number' }
export interface Action { key: ActionKey; label: string; enabled: boolean }
export interface Row { id: number | string; actions: Action[]; [key: string]: Scalar | Action[] }
export interface Feature { key: string; label: string; installed: boolean; enabled: boolean }
export interface RuntimeItem { label: string; value: string; hint: string }
export interface WorkbenchData {
  section: Section;
  module: { installed: boolean; enabled: boolean; message: string };
  cards: Card[];
  columns: Column[];
  rows: Row[];
  pagination: { page: number; per_page: number; total: number; last_page: number };
  bulkActions: Action[];
  features: Feature[];
  runtime: RuntimeItem[];
  trend?: { day: string; orders: number; sales: number }[];
}
export interface ActionResult { message: string; details: { label: string; value: string }[] }
export interface Query { page: number; state: State; search: string }
