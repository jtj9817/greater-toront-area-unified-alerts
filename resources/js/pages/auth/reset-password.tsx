import { Form, Head } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import AuthLayout from '@/layouts/auth-layout';
import { update } from '@/routes/password';

type Props = {
    token: string;
    email: string;
};

export default function ResetPassword({ token, email }: Props) {
    return (
        <AuthLayout
            title="Reset password"
            description="Please enter your new password below"
        >
            <Head title="Reset password" />

            <Form
                id="auth-reset-password-form"
                {...update.form()}
                transform={(data) => ({ ...data, token, email })}
                resetOnSuccess={['password', 'password_confirmation']}
            >
                {({ processing, errors }) => (
                    <div id="auth-reset-password-fields" className="grid gap-6">
                        <div id="auth-reset-password-email-group" className="grid gap-2">
                            <Label
                                id="auth-reset-password-email-label"
                                htmlFor="auth-reset-password-email-input"
                            >
                                Email
                            </Label>
                            <Input
                                id="auth-reset-password-email-input"
                                type="email"
                                name="email"
                                autoComplete="email"
                                value={email}
                                className="mt-1 block w-full"
                                readOnly
                            />
                            <InputError
                                id="auth-reset-password-email-error"
                                message={errors.email}
                                className="mt-2"
                            />
                        </div>

                        <div id="auth-reset-password-password-group" className="grid gap-2">
                            <Label
                                id="auth-reset-password-password-label"
                                htmlFor="auth-reset-password-password-input"
                            >
                                Password
                            </Label>
                            <Input
                                id="auth-reset-password-password-input"
                                type="password"
                                name="password"
                                autoComplete="new-password"
                                className="mt-1 block w-full"
                                autoFocus
                                placeholder="Password"
                            />
                            <InputError
                                id="auth-reset-password-password-error"
                                message={errors.password}
                            />
                        </div>

                        <div
                            id="auth-reset-password-confirmation-group"
                            className="grid gap-2"
                        >
                            <Label
                                id="auth-reset-password-confirmation-label"
                                htmlFor="auth-reset-password-confirmation-input"
                            >
                                Confirm password
                            </Label>
                            <Input
                                id="auth-reset-password-confirmation-input"
                                type="password"
                                name="password_confirmation"
                                autoComplete="new-password"
                                className="mt-1 block w-full"
                                placeholder="Confirm password"
                            />
                            <InputError
                                id="auth-reset-password-confirmation-error"
                                message={errors.password_confirmation}
                                className="mt-2"
                            />
                        </div>

                        <Button
                            id="auth-reset-password-submit-btn"
                            type="submit"
                            className="mt-4 w-full"
                            disabled={processing}
                            data-test="reset-password-button"
                        >
                            {processing && <Spinner />}
                            Reset password
                        </Button>
                    </div>
                )}
            </Form>
        </AuthLayout>
    );
}
