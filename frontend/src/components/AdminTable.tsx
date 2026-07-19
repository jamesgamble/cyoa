import type { ReactNode } from "react";

export interface AdminColumn<T> {
  key: string;
  header: string;
  cell: (row: T) => ReactNode;
  scope?: "col" | "row";
  align?: "left" | "right";
}

interface Props<T> {
  caption: string;
  columns: AdminColumn<T>[];
  rows: T[];
  /** Unique key extractor for each row. */
  rowKey: (row: T) => string;
  /** Message shown in place of the table body when rows is empty. */
  emptyMessage?: string;
}

/**
 * Administrative table — a semantic table with caption, column
 * headers, zebra striping, and an accessible empty state. Layout is
 * fully owned by CSS; row content is rendered through per-column
 * `cell` render functions.
 */
export function AdminTable<T>({ caption, columns, rows, rowKey, emptyMessage = "No records." }: Props<T>) {
  return (
    <table className="bp-table" data-testid="admin-table">
      <caption>{caption}</caption>
      <thead>
        <tr>
          {columns.map((c) => (
            <th key={c.key} scope="col" style={c.align === "right" ? { textAlign: "right" } : undefined}>
              {c.header}
            </th>
          ))}
        </tr>
      </thead>
      <tbody>
        {rows.length === 0 ? (
          <tr>
            <td colSpan={columns.length} className="bp-table__empty">{emptyMessage}</td>
          </tr>
        ) : (
          rows.map((r) => (
            <tr key={rowKey(r)}>
              {columns.map((c) => (
                <td key={c.key} style={c.align === "right" ? { textAlign: "right" } : undefined}>
                  {c.cell(r)}
                </td>
              ))}
            </tr>
          ))
        )}
      </tbody>
    </table>
  );
}
