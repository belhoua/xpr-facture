"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import { Plus } from "lucide-react";
import { useTranslations } from "next-intl";
import { useState } from "react";
import { Controller, useForm } from "react-hook-form";

import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@/components/ui/dialog";
import { Field, FieldError, FieldGroup, FieldLabel } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { inviteUser, userKeys } from "@/features/users/api/users";
import {
  inviteUserSchema,
  type InviteUserValues,
} from "@/features/users/schemas/user";

const INVITABLE_ROLES = ["admin", "accountant", "sales", "viewer"] as const;

/**
 * Ajout d'un collaborateur. En cas de succès, on invalide la liste plutôt que
 * de l'éditer à la main : le serveur reste l'autorité sur l'état réel de
 * l'invitation (elle peut échouer, être déjà en attente, etc.).
 */
export function InviteUserDialog() {
  const t = useTranslations("users");
  const tRoot = useTranslations();
  const [open, setOpen] = useState(false);
  const queryClient = useQueryClient();

  const form = useForm<InviteUserValues>({
    resolver: zodResolver(inviteUserSchema),
    defaultValues: { name: "", email: "", role: "sales" },
  });

  const mutation = useMutation({
    mutationFn: inviteUser,
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: userKeys.all });
      form.reset();
      setOpen(false);
    },
  });

  const errors = form.formState.errors;
  const fieldError = (message?: string): string | undefined =>
    message?.startsWith("validation.") ? tRoot(message) : message;

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild>
        <Button size="sm">
          <Plus aria-hidden />
          {t("invite")}
        </Button>
      </DialogTrigger>

      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>{t("dialog.title")}</DialogTitle>
          <DialogDescription>{t("dialog.description")}</DialogDescription>
        </DialogHeader>

        <form
          id="invite-user"
          onSubmit={form.handleSubmit((values) => mutation.mutate(values))}
        >
          <FieldGroup>
            <Field>
              <FieldLabel htmlFor="invite-name">{t("dialog.name")}</FieldLabel>
              <Input
                id="invite-name"
                aria-invalid={Boolean(errors.name)}
                {...form.register("name")}
              />
              <FieldError>{fieldError(errors.name?.message)}</FieldError>
            </Field>

            <Field>
              <FieldLabel htmlFor="invite-email">{t("dialog.email")}</FieldLabel>
              <Input
                id="invite-email"
                type="email"
                autoComplete="email"
                aria-invalid={Boolean(errors.email)}
                {...form.register("email")}
              />
              <FieldError>{fieldError(errors.email?.message)}</FieldError>
            </Field>

            <Field>
              <FieldLabel htmlFor="invite-role">{t("dialog.role")}</FieldLabel>
              <Controller
                control={form.control}
                name="role"
                render={({ field }) => (
                  <Select value={field.value} onValueChange={field.onChange}>
                    <SelectTrigger id="invite-role">
                      <SelectValue placeholder={t("dialog.rolePlaceholder")} />
                    </SelectTrigger>
                    <SelectContent>
                      {INVITABLE_ROLES.map((role) => (
                        <SelectItem key={role} value={role}>
                          {t(`roles.${role}`)}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                )}
              />
              <FieldError>{fieldError(errors.role?.message)}</FieldError>
            </Field>
          </FieldGroup>
        </form>

        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            onClick={() => setOpen(false)}
            disabled={mutation.isPending}
          >
            {tRoot("common.cancel")}
          </Button>
          <Button type="submit" form="invite-user" disabled={mutation.isPending}>
            {mutation.isPending ? t("dialog.submitting") : t("dialog.submit")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
