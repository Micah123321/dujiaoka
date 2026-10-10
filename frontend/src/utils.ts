export function money(value: string | number) { const number = Number(value); return Number.isFinite(number) ? number.toFixed(2) : '0.00'; }
export function unitPrice(base: string, tiers: {number: number; price: string}[], quantity: number) {
  // The backend uses the last matching configured tier and ignores non-positive prices.
  let wholesale = 0;
  for (const tier of tiers) if (quantity >= Number(tier.number)) wholesale = Number(tier.price);
  return wholesale > 0 ? wholesale : Number(base);
}
export function safeUrl(value: string, allowApp = false) {
  if (!value) return '';
  try { const url = new URL(value, window.location.origin); return ['http:', 'https:'].includes(url.protocol) || (allowApp && ['alipays:', 'weixin:'].includes(url.protocol)) ? url.href : ''; } catch { return ''; }
}
export async function copyText(value: string, target?: HTMLTextAreaElement) {
  try { await navigator.clipboard.writeText(value); return true; } catch {
    if (!target) return false;
    target.focus(); target.select();
    try { return document.execCommand('copy'); } catch { return false; }
  }
}
