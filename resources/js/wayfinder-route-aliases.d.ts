/**
 * Type-only aliases for Wayfinder-generated routes.
 *
 * Wayfinder generates `resources/js/routes/<group>/index.ts`, but the app imports
 * `@/routes/<group>`. With `moduleResolution: "bundler"`, TypeScript does not
 * resolve directory indexes for ESM-style imports, so we provide explicit module
 * declarations that re-export from the generated `index.ts` files.
 *
 * Runtime behavior is unchanged (Vite resolves the folder import); this file is
 * only for TypeScript's module resolver.
 */

declare module '@/routes/appearance' {
    export * from '@/routes/appearance/index';
    export { default } from '@/routes/appearance/index';
}

declare module '@/routes/login' {
    export * from '@/routes/login/index';
    export { default } from '@/routes/login/index';
}

declare module '@/routes/notifications' {
    export * from '@/routes/notifications/index';
    export { default } from '@/routes/notifications/index';
}

declare module '@/routes/password' {
    export * from '@/routes/password/index';
    export { default } from '@/routes/password/index';
}

declare module '@/routes/profile' {
    export * from '@/routes/profile/index';
    export { default } from '@/routes/profile/index';
}

declare module '@/routes/register' {
    export * from '@/routes/register/index';
    export { default } from '@/routes/register/index';
}

declare module '@/routes/storage' {
    export * from '@/routes/storage/index';
    export { default } from '@/routes/storage/index';
}

declare module '@/routes/two-factor' {
    export * from '@/routes/two-factor/index';
    export { default } from '@/routes/two-factor/index';
}

declare module '@/routes/user-password' {
    export * from '@/routes/user-password/index';
    export { default } from '@/routes/user-password/index';
}

declare module '@/routes/verification' {
    export * from '@/routes/verification/index';
    export { default } from '@/routes/verification/index';
}
