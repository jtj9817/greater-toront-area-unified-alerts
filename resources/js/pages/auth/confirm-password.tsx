import { Form, Head } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import AuthLayout from '@/layouts/auth-layout';
import { store } from '@/routes/password/confirm';

export default function ConfirmPassword() {
    return (
        <AuthLayout
            title="Confirm your password"
            description="This is a secure area of the application. Please confirm your password before continuing."
        >
            <Head title="Confirm password" />

            <Form
                id="auth-confirm-password-form"
                {...store.form()}
                resetOnSuccess={['password']}
            >
                {({ processing, errors }) => (
                    <div
                        id="auth-confirm-password-content"
                        className="space-y-6"
                    >
                        <div
                            id="auth-confirm-password-input-group"
                            className="grid gap-2"
                        >
                            <Label
                                id="auth-confirm-password-label"
                                htmlFor="auth-confirm-password-input"
                            >
                                Password
                            </Label>
                            <Input
                                id="auth-confirm-password-input"
                                type="password"
                                name="password"
                                placeholder="Password"
                                autoComplete="current-password"
                                autoFocus
                            />

                            <InputError
                                id="auth-confirm-password-error"
                                message={errors.password}
                            />
                        </div>

                        <div className="flex items-center">
                            <Button
                                id="auth-confirm-password-submit-btn"
                                className="w-full"
                                disabled={processing}
                                data-test="confirm-password-button"
                            >
                                {processing && <Spinner />}
                                Confirm password
                            </Button>
                        </div>
                    </div>
                )}
            </Form>
        </AuthLayout>
    );
}
