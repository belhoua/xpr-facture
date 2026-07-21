"use client";

import { useQuery } from "@tanstack/react-query";
import { Users } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";

import { DataTable, type Column } from "@/components/patterns/data-table";
import { PageHeader } from "@/components/patterns/page-header";
import { Avatar, AvatarFallback } from "@/components/ui/avatar";
import { Badge } from "@/components/ui/badge";
import { fetchCompanyUsers, userKeys } from "@/features/users/api/users";
import { InviteUserDialog } from "@/features/users/components/invite-user-dialog";
import type { CompanyUser } from "@/features/users/schemas/user";
import { toApiProblem } from "@/lib/api/client";
import { formatDate } from "@/lib/format";

/** Initiales pour l'avatar de repli : « Othmane Belhouari » → « OB ». */
function initials(name: string): string {
  return name
    .split(/\s+/)
    .slice(0, 2)
    .map((part) => part.charAt(0).toUpperCase())
    .join("");
}

export function UsersView() {
  const t = useTranslations("users");
  const locale = useLocale();

  const { data, isPending, isError, error, refetch } = useQuery({
    queryKey: userKeys.list(),
    queryFn: fetchCompanyUsers,
  });

  const columns: readonly Column<CompanyUser>[] = [
    {
      id: "user",
      header: t("columns.user"),
      cell: (row) => (
        <div className="flex items-center gap-2.5">
          <Avatar className="size-7">
            <AvatarFallback className="bg-primary/10 text-primary text-xs font-medium">
              {initials(row.name)}
            </AvatarFallback>
          </Avatar>
          <div className="min-w-0">
            <p className="truncate font-medium">{row.name}</p>
            <p className="text-muted-foreground truncate text-xs">
              {row.email}
            </p>
          </div>
        </div>
      ),
    },
    {
      id: "role",
      header: t("columns.role"),
      cell: (row) => <Badge variant="secondary">{t(`roles.${row.role}`)}</Badge>,
    },
    {
      id: "state",
      header: t("columns.status"),
      hideBelow: "md",
      cell: (row) => (
        <span
          className={
            row.state === "active"
              ? "text-status-paid text-xs font-medium"
              : "text-status-partial text-xs font-medium"
          }
        >
          {t(`state.${row.state}`)}
        </span>
      ),
    },
    {
      id: "lastActive",
      header: t("columns.lastActive"),
      align: "end",
      hideBelow: "lg",
      cell: (row) => (
        <span className="text-muted-foreground tabular">
          {row.lastActiveAt ? formatDate(row.lastActiveAt, locale) : "—"}
        </span>
      ),
    },
  ];

  return (
    <>
      <PageHeader
        title={t("title")}
        description={t("description")}
        actions={<InviteUserDialog />}
      />

      <DataTable
        rows={data ?? []}
        columns={columns}
        rowKey={(row) => row.id}
        status={isPending ? "pending" : isError ? "error" : "success"}
        errorDetail={isError ? toApiProblem(error).detail : undefined}
        onRetry={() => void refetch()}
        empty={{
          icon: Users,
          title: t("empty.title"),
          description: t("empty.description"),
          action: <InviteUserDialog />,
        }}
      />
    </>
  );
}
