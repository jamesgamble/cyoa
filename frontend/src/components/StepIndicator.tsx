interface Step {
  label: string;
}

interface Props {
  steps: Step[];
  /** 1-based index of the current step. */
  current: number;
  /** Optional accessible label for the whole indicator. */
  ariaLabel?: string;
}

export function StepIndicator({ steps, current, ariaLabel = "Progress" }: Props) {
  return (
    <ol className="bp-steps" aria-label={ariaLabel} data-testid="step-indicator">
      {steps.map((s, i) => {
        const n = i + 1;
        const state = n < current ? "done" : n === current ? "current" : "todo";
        return (
          <li key={i} className={`bp-step bp-step--${state}`} aria-current={state === "current" ? "step" : undefined}>
            <span className="bp-step__num" aria-hidden="true">{n}</span>
            <span className="bp-step__label">{s.label}</span>
          </li>
        );
      })}
    </ol>
  );
}
