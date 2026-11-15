import { DataTable, type DataColumn, type FilterDef } from './data-table'

/** Column definition for a list row of type T (same as DataColumn). */
export type ResourceColumn<T extends object> = DataColumn<T>

type ResourceTableProps<T extends object> = {
  queryKey: string
  path: string
  columns: ResourceColumn<T>[]
  params?: Record<string, string | number | boolean | undefined | null>
  search?: { placeholder: string; param?: string } | false
  filters?: FilterDef[]
  defaultSort?: string
  selectable?: boolean
  bulkActions?: (rows: T[], clear: () => void) => React.ReactNode
  onRowClick?: (row: T) => void
  empty?: string
  rowId?: (row: T) => string
  actions?: React.ReactNode
}

/** A server-paged DataTable for any Laravel paginator endpoint. */
export function ResourceTable<T extends object>({ queryKey, search = false, ...rest }: ResourceTableProps<T>) {
  return <DataTable<T> id={queryKey} exportName={queryKey} search={search} {...rest} />
}
