import { Form } from '@inertiajs/react';
import { useRef } from 'react';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export default function DeleteUser() {
    const passwordInput = useRef<HTMLInputElement>(null);

    return (
        <div id="delete-user-section" className="space-y-6">
            <Heading
                id="delete-user-heading"
                variant="small"
                title="Delete account"
                description="Delete your account and all of its resources"
            />
            <div id="delete-user-warning-box" className="space-y-4 rounded-lg border border-red-100 bg-red-50 p-4 dark:border-red-200/10 dark:bg-red-700/10">
                <div id="delete-user-warning-text" className="relative space-y-0.5 text-red-600 dark:text-red-100">
                    <p id="delete-user-warning-title" className="font-medium">Warning</p>
                    <p id="delete-user-warning-message" className="text-sm">
                        Please proceed with caution, this cannot be undone.
                    </p>
                </div>

                <Dialog>
                    <DialogTrigger id="delete-user-trigger" asChild>
                        <Button
                            id="delete-user-button"
                            variant="destructive"
                            data-test="delete-user-button"
                        >
                            Delete account
                        </Button>
                    </DialogTrigger>
                    <DialogContent id="delete-user-dialog">
                        <DialogTitle id="delete-user-dialog-title">
                            Are you sure you want to delete your account?
                        </DialogTitle>
                        <DialogDescription id="delete-user-dialog-description">
                            Once your account is deleted, all of its resources
                            and data will also be permanently deleted. Please
                            enter your password to confirm you would like to
                            permanently delete your account.
                        </DialogDescription>

                        <Form
                            {...ProfileController.destroy.form()}
                            options={{
                                preserveScroll: true,
                            }}
                            onError={() => passwordInput.current?.focus()}
                            resetOnSuccess
                            className="space-y-6"
                        >
                            {({ resetAndClearErrors, processing, errors }) => (
                                <>
                                    <div id="delete-user-form-group" className="grid gap-2">
                                        <Label
                                            id="delete-user-password-label"
                                            htmlFor="password"
                                            className="sr-only"
                                        >
                                            Password
                                        </Label>

                                        <Input
                                            id="password"
                                            type="password"
                                            name="password"
                                            ref={passwordInput}
                                            placeholder="Password"
                                            autoComplete="current-password"
                                        />

                                        <InputError id="delete-user-password-error" message={errors.password} />
                                    </div>

                                    <DialogFooter id="delete-user-dialog-footer" className="gap-2">
                                        <DialogClose id="delete-user-cancel-close" asChild>
                                            <Button
                                                id="delete-user-cancel-button"
                                                variant="secondary"
                                                onClick={() =>
                                                    resetAndClearErrors()
                                                }
                                            >
                                                Cancel
                                            </Button>
                                        </DialogClose>

                                        <Button
                                            id="delete-user-confirm-button"
                                            variant="destructive"
                                            disabled={processing}
                                            asChild
                                        >
                                            <button
                                                type="submit"
                                                data-test="confirm-delete-user-button"
                                            >
                                                Delete account
                                            </button>
                                        </Button>
                                    </DialogFooter>
                                </>
                            )}
                        </Form>
                    </DialogContent>
                </Dialog>
            </div>
        </div>
    );
}
