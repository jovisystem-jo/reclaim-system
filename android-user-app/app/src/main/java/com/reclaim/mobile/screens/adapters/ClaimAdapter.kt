package com.reclaim.mobile.screens.adapters

import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.widget.TextView
import androidx.recyclerview.widget.RecyclerView
import com.google.android.material.button.MaterialButton
import com.reclaim.mobile.R
import com.reclaim.mobile.models.Claim

class ClaimAdapter(
    private val onPrimaryAction: (Claim) -> Unit,
    private val onSecondaryAction: (Claim) -> Unit
) : RecyclerView.Adapter<ClaimAdapter.ClaimViewHolder>() {

    private val claims = mutableListOf<Claim>()

    fun submitList(newClaims: List<Claim>) {
        claims.clear()
        claims.addAll(newClaims)
        notifyDataSetChanged()
    }

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): ClaimViewHolder {
        val view = LayoutInflater.from(parent.context).inflate(R.layout.row_claim, parent, false)
        return ClaimViewHolder(view, onPrimaryAction, onSecondaryAction)
    }

    override fun onBindViewHolder(holder: ClaimViewHolder, position: Int) {
        holder.bind(claims[position])
    }

    override fun getItemCount(): Int = claims.size

    class ClaimViewHolder(
        itemView: View,
        private val onPrimaryAction: (Claim) -> Unit,
        private val onSecondaryAction: (Claim) -> Unit
    ) : RecyclerView.ViewHolder(itemView) {
        private val title: TextView = itemView.findViewById(R.id.textClaimTitle)
        private val status: TextView = itemView.findViewById(R.id.textClaimStatus)
        private val description: TextView = itemView.findViewById(R.id.textClaimDescription)
        private val primaryButton: MaterialButton = itemView.findViewById(R.id.buttonClaimPrimary)
        private val secondaryButton: MaterialButton = itemView.findViewById(R.id.buttonClaimSecondary)

        fun bind(claim: Claim) {
            title.text = claim.item.title
            status.text = claim.status.uppercase()
            description.text = claim.claimantDescription.ifBlank { claim.item.description }
            secondaryButton.text = "View Item"
            secondaryButton.setOnClickListener { onSecondaryAction(claim) }

            when (claim.status) {
                "pending" -> {
                    primaryButton.visibility = View.VISIBLE
                    primaryButton.text = "Cancel"
                    primaryButton.setOnClickListener { onPrimaryAction(claim) }
                }
                "approved" -> {
                    primaryButton.visibility = View.VISIBLE
                    primaryButton.text = "Confirm Reclaim"
                    primaryButton.setOnClickListener { onPrimaryAction(claim) }
                }
                else -> primaryButton.visibility = View.GONE
            }
        }
    }
}
