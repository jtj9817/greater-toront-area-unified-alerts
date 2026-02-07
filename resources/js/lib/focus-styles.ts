/**
 * Shared focus outline styles for consistent focus indicators across UI components.
 *
 * Usage:
 *   import { focusOutline, focusOutlineInvalid } from "@/lib/focus-styles";
 *   cn("...", focusOutline, focusOutlineInvalid)
 */

/** Base focus outline styles - applies to all focusable elements */
export const focusOutline = [
    'outline-none',
    'focus-visible:outline-2',
    'focus-visible:outline-ring',
    'focus-visible:outline-offset-2',
] as const;

/** Invalid state outline styles - applies when aria-invalid is true */
export const focusOutlineInvalid = [
    'aria-invalid:outline-destructive/50',
    'dark:aria-invalid:outline-destructive/50',
] as const;

/** Combined focus outline styles for convenience */
export const focusOutlineAll = [
    ...focusOutline,
    ...focusOutlineInvalid,
] as const;

/** Sidebar-specific focus outline using sidebar-ring color */
export const focusOutlineSidebar = [
    'outline-none',
    'focus-visible:outline-2',
    'focus-visible:outline-sidebar-ring',
    'focus-visible:outline-offset-2',
] as const;
