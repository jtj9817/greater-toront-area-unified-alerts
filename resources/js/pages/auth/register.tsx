import { Form, Head } from '@inertiajs/react';
import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import AuthLayout from '@/layouts/auth-layout';
import { login } from '@/routes';
import { store } from '@/routes/register';

export default function Register() {
    return (
        <AuthLayout
            title="Create an account"
            description="Enter your details below to create your account"
        >
            <Head title="Register" />
            <Form
                id="auth-register-form"
                {...store.form()}
                resetOnSuccess={['password', 'password_confirmation']}
                disableWhileProcessing
                className="flex flex-col gap-6"
            >
                {({ processing, errors }) => (
                    <>
                        <div id="auth-register-fields" className="grid gap-6">
                            <div
                                id="auth-register-name-group"
                                className="grid gap-2"
                            >
                                <Label
                                    id="auth-register-name-label"
                                    htmlFor="auth-register-name-input"
                                >
                                    Name
                                </Label>
                                <Input
                                    id="auth-register-name-input"
                                    type="text"
                                    required
                                    autoFocus
                                    tabIndex={1}
                                    autoComplete="name"
                                    name="name"
                                    placeholder="Full name"
                                />
                                <InputError
                                    id="auth-register-name-error"
                                    message={errors.name}
                                    className="mt-2"
                                />
                            </div>

                            <div
                                id="auth-register-email-group"
                                className="grid gap-2"
                            >
                                <Label
                                    id="auth-register-email-label"
                                    htmlFor="auth-register-email-input"
                                >
                                    Email address
                                </Label>
                                <Input
                                    id="auth-register-email-input"
                                    type="email"
                                    required
                                    tabIndex={2}
                                    autoComplete="email"
                                    name="email"
                                    placeholder="email@example.com"
                                />
                                <InputError
                                    id="auth-register-email-error"
                                    message={errors.email}
                                />
                            </div>

                            <div
                                id="auth-register-password-group"
                                className="grid gap-2"
                            >
                                <Label
                                    id="auth-register-password-label"
                                    htmlFor="auth-register-password-input"
                                >
                                    Password
                                </Label>
                                <Input
                                    id="auth-register-password-input"
                                    type="password"
                                    required
                                    tabIndex={3}
                                    autoComplete="new-password"
                                    name="password"
                                    placeholder="Password"
                                />
                                <InputError
                                    id="auth-register-password-error"
                                    message={errors.password}
                                />
                            </div>

                            <div
                                id="auth-register-password-confirmation-group"
                                className="grid gap-2"
                            >
                                <Label
                                    id="auth-register-password-confirmation-label"
                                    htmlFor="auth-register-password-confirmation-input"
                                >
                                    Confirm password
                                </Label>
                                <Input
                                    id="auth-register-password-confirmation-input"
                                    type="password"
                                    required
                                    tabIndex={4}
                                    autoComplete="new-password"
                                    name="password_confirmation"
                                    placeholder="Confirm password"
                                />
                                <InputError
                                    id="auth-register-password-confirmation-error"
                                    message={errors.password_confirmation}
                                />
                            </div>

                            <Button
                                id="auth-register-submit-btn"
                                type="submit"
                                className="mt-2 w-full"
                                tabIndex={5}
                                data-test="register-user-button"
                            >
                                {processing && <Spinner />}
                                Create account
                            </Button>
                        </div>

                        <div
                            id="auth-register-login-prompt"
                            className="text-center text-sm text-muted-foreground"
                        >
                            Already have an account?{' '}
                            <TextLink
                                id="auth-register-login-link"
                                href={login()}
                                tabIndex={6}
                            >
                                Log in
                            </TextLink>
                        </div>
                    </>
                )}
            </Form>
        </AuthLayout>
    );
}
