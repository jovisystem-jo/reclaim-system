package com.reclaim.mobile.screens.adapters

import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.widget.TextView
import androidx.recyclerview.widget.RecyclerView
import com.reclaim.mobile.R
import com.reclaim.mobile.models.Item

class ItemAdapter(
    private val onClick: (Item) -> Unit
) : RecyclerView.Adapter<ItemAdapter.ItemViewHolder>() {

    private val items = mutableListOf<Item>()

    fun submitList(newItems: List<Item>) {
        items.clear()
        items.addAll(newItems)
        notifyDataSetChanged()
    }

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): ItemViewHolder {
        val view = LayoutInflater.from(parent.context).inflate(R.layout.row_item, parent, false)
        return ItemViewHolder(view, onClick)
    }

    override fun onBindViewHolder(holder: ItemViewHolder, position: Int) {
        holder.bind(items[position])
    }

    override fun getItemCount(): Int = items.size

    class ItemViewHolder(
        itemView: View,
        private val onClick: (Item) -> Unit
    ) : RecyclerView.ViewHolder(itemView) {
        private val title: TextView = itemView.findViewById(R.id.textItemTitle)
        private val status: TextView = itemView.findViewById(R.id.textItemStatus)
        private val meta: TextView = itemView.findViewById(R.id.textItemMeta)

        fun bind(item: Item) {
            title.text = item.title
            status.text = "${item.status.uppercase()} • ${item.category}"
            meta.text = buildString {
                append(item.foundLocation.ifBlank { item.location })
                if ((item.claimCount ?: 0) > 0) {
                    append(" • Claims: ${item.claimCount}")
                }
            }
            itemView.setOnClickListener { onClick(item) }
        }
    }
}
