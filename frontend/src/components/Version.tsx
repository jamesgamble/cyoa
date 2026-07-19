import { APP_VERSION } from "../lib/version";

interface VersionProps {
  prefix?: string;
  className?: string;
}

export function Version({ prefix = "v", className }: VersionProps) {
  return (
    <span className={className} data-testid="app-version">
      {prefix}
      {APP_VERSION}
    </span>
  );
}
