import { describe, expect, it, vi } from 'vitest'
import { render, fireEvent } from '@testing-library/react'
import { CaptureForm } from '../CaptureForm'

describe('CaptureForm', () => {
  it('calls onSubmit with the typed text', async () => {
    const onSubmit = vi.fn().mockResolvedValue(undefined)
    const { container } = render(<CaptureForm onSubmit={onSubmit} />)
    const textarea = container.querySelector('textarea') as HTMLTextAreaElement
    fireEvent.change(textarea, { target: { value: 'hello' } })
    fireEvent.submit(container.querySelector('form') as HTMLFormElement)
    await Promise.resolve()
    expect(onSubmit).toHaveBeenCalledWith('hello')
  })
})
