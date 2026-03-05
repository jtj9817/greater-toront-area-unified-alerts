import { Form, Head } from '@inertiajs/react';
import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import AuthLayout from '@/layouts/auth-layout';
import { register } from '@/routes';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

type Props = {
    status?: string;
    canResetPassword: boolean;
    canRegister: boolean;
};

export default function Login({
    status,
    canResetPassword,
    canRegister,
}: Props) {
    return (
        <AuthLayout
            title="Log in to your account"
            description="Enter your email and password below to log in"
        >
            <Head title="Log in" />

            <Form
                id="login-form"
                {...store.form()}
                resetOnSuccess={['password']}
                className="flex flex-col gap-6"
            >
                {({ processing, errors }) => (
                    <>
                        <div id="login-form-fields" className="grid gap-6">
                            <div id="login-email-group" className="grid gap-2">
                                <Label
                                    id="login-email-label"
                                    htmlFor="auth-login-email-input"
                                >
                                    Email address
                                </Label>
                                <Input
                                    id="auth-login-email-input"
                                    type="email"
                                    name="email"
                                    required
                                    autoFocus
                                    tabIndex={1}
                                    autoComplete="email"
                                    placeholder="email@example.com"
                                />
                                <InputError
                                    id="login-email-error"
                                    message={errors.email}
                                />
                            </div>

                            <div
                                id="login-password-group"
                                className="grid gap-2"
                            >
                                <div
                                    id="login-password-label-row"
                                    className="flex items-center"
                                >
                                    <Label
                                        id="login-password-label"
                                        htmlFor="auth-login-password-input"
                                    >
                                        Password
                                    </Label>
                                    {canResetPassword && (
                                        <TextLink
                                            id="login-forgot-password-link"
                                            href={request()}
                                            className="ml-auto text-sm"
                                            tabIndex={5}
                                        >
                                            Forgot password?
                                        </TextLink>
                                    )}
                                </div>
                                <Input
                                    id="auth-login-password-input"
                                    type="password"
                                    name="password"
                                    required
                                    tabIndex={2}
                                    autoComplete="current-password"
                                    placeholder="Password"
                                />
                                <InputError
                                    id="login-password-error"
                                    message={errors.password}
                                />
                            </div>

                            <div
                                id="login-remember-group"
                                className="flex items-center space-x-3"
                            >
                                <Checkbox
                                    id="remember"
                                    name="remember"
                                    tabIndex={3}
                                />
                                <Label
                                    id="login-remember-label"
                                    htmlFor="remember"
                                >
                                    Remember me
                                </Label>
                            </div>

                            <Button
                                id="login-submit-button"
                                type="submit"
                                className="mt-4 w-full"
                                tabIndex={4}
                                disabled={processing}
                                data-test="login-button"
                            >
                                {processing && <Spinner />}
                                Log in
                            </Button>
                        </div>

                        {canRegister && (
                            <div
                                id="login-register-prompt"
                                className="text-center text-sm text-muted-foreground"
                            >
                                Don't have an account?{' '}
                                <TextLink
                                    id="login-register-link"
                                    href={register()}
                                    tabIndex={5}
                                >
                                    Sign up
                                </TextLink>
                            </div>
                        )}
                    </>
                )}
            </Form>

            {status && (
                <div
                    id="login-status-message"
                    className="mb-4 text-center text-sm font-medium text-green-600"
                >
                    {status}
                </div>
            )}
        </AuthLayout>
    );
}
