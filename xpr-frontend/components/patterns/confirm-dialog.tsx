"use client";

import { useTranslations } from "next-intl";

import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";

/**
 * Confirmation d'une action irréversible (suppression, annulation). Mutualisée
 * pour qu'aucun écran ne réinvente une boîte de dialogue destructive — et donc
 * n'en oublie l'état « en cours » qui protège du double-clic.
 *
 * Piloté de l'extérieur (`open`/`onOpenChange`) : l'appelant garde la main sur
 * la ligne concernée, ce composant ne connaît que le texte et l'action.
 */
interface ConfirmDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  title: string;
  description: string;
  confirmLabel: string;
  onConfirm: () => void;
  pending?: boolean;
  variant?: "default" | "destructive";
}

export function ConfirmDialog({
  open,
  onOpenChange,
  title,
  description,
  confirmLabel,
  onConfirm,
  pending = false,
  variant = "destructive",
}: ConfirmDialogProps) {
  const tCommon = useTranslations("common");

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-sm">
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          <DialogDescription>{description}</DialogDescription>
        </DialogHeader>

        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={pending}
          >
            {tCommon("cancel")}
          </Button>
          {/* `loading` et non `disabled` : la confirmation déclenche une
              suppression ou un archivage, l'action la plus anxiogène de
              l'écran. Le spinner atteste que le clic est parti. */}
          <Button
            type="button"
            variant={variant}
            onClick={onConfirm}
            loading={pending}
          >
            {pending ? tCommon("saving") : confirmLabel}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
